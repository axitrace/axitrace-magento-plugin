<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Observer;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../magento-stubs.php';

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\Consent\CookieRestrictionConsentResolver;
use AxiTrace\Tracking\Model\EventId\UuidV5Generator;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use AxiTrace\Tracking\Model\EventLog\EventLogFactory;
use AxiTrace\Tracking\Model\Queue\OrderEventPublisher;
use AxiTrace\Tracking\Observer\OrderStateTransitionObserver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event as MagentoEvent;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The observer is the request-scoped capture point: it must hand the Cookie
 * Restriction Mode decision to OrderEventPublisher::publishOrder() alongside the
 * Meta cookies, and must stamp nothing outside the frontend area.
 */
class OrderStateTransitionObserverTest extends TestCase
{
    private const STORE_ID   = 3;
    private const WEBSITE_ID = 1;

    /** @var MockObject&OrderEventPublisher */
    private MockObject $publisher;

    /** @var MockObject&CookieManagerInterface */
    private MockObject $cookieManager;

    /** @var MockObject&ScopeConfigInterface */
    private MockObject $scopeConfig;

    /** @var MockObject&State */
    private MockObject $appState;

    private ?string $publishedConsent = null;
    private bool $published = false;

    protected function setUp(): void
    {
        $this->publishedConsent = null;
        $this->published = false;

        $this->publisher     = $this->createMock(OrderEventPublisher::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->scopeConfig   = $this->createMock(ScopeConfigInterface::class);
        $this->appState      = $this->createMock(State::class);
    }

    public function testFrontendWithAcceptedCookiePublishesGranted(): void
    {
        $this->givenRestrictionMode(true);
        $this->givenAreaCode('frontend');
        $this->givenConsentCookie('{"1":1}');

        $this->whenOrderMovesToProcessing();

        self::assertTrue($this->published);
        self::assertSame('granted', $this->publishedConsent);
    }

    public function testFrontendWithoutCookiePublishesDenied(): void
    {
        $this->givenRestrictionMode(true);
        $this->givenAreaCode('frontend');
        $this->givenConsentCookie(null);

        $this->whenOrderMovesToProcessing();

        self::assertTrue($this->published);
        self::assertSame('denied', $this->publishedConsent);
    }

    public function testRestrictionModeOffPublishesNoConsent(): void
    {
        $this->givenRestrictionMode(false);
        $this->givenAreaCode('frontend');
        $this->givenConsentCookie(null);

        $this->whenOrderMovesToProcessing();

        self::assertTrue($this->published);
        self::assertNull($this->publishedConsent);
    }

    public function testAdminAreaPublishesNoConsentEvenWithoutCookie(): void
    {
        // An admin invoice has no buyer cookies: it must never be recorded as a refusal.
        $this->givenRestrictionMode(true);
        $this->givenAreaCode('adminhtml');
        $this->givenConsentCookie(null);

        $this->whenOrderMovesToProcessing();

        self::assertTrue($this->published);
        self::assertNull($this->publishedConsent);
    }

    public function testUnsetAreaCodePublishesNoConsent(): void
    {
        $this->givenRestrictionMode(true);
        $this->appState->method('getAreaCode')
            ->willThrowException(new \RuntimeException('Area code is not set'));
        $this->givenConsentCookie(null);

        $this->whenOrderMovesToProcessing();

        self::assertTrue($this->published);
        self::assertNull($this->publishedConsent);
    }

    public function testConsentReadFailureStillPublishesTheOrder(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willThrowException(new \RuntimeException('config backend down'));
        $this->givenAreaCode('frontend');
        $this->givenConsentCookie('{"1":1}');

        $this->whenOrderMovesToProcessing();

        self::assertTrue($this->published, 'A consent read failure must not lose the purchase.');
        self::assertNull($this->publishedConsent);
    }

    private function givenRestrictionMode(bool $enabled): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with(
                CookieRestrictionConsentResolver::XML_PATH_COOKIE_RESTRICTION,
                'store',
                self::STORE_ID
            )
            ->willReturn($enabled);
    }

    private function givenAreaCode(string $areaCode): void
    {
        $this->appState->method('getAreaCode')->willReturn($areaCode);
    }

    private function givenConsentCookie(?string $value): void
    {
        $this->cookieManager->method('getCookie')->willReturnCallback(
            static fn (string $name): ?string => $name === CookieRestrictionConsentResolver::COOKIE_NAME
                ? $value
                : null
        );
    }

    private function whenOrderMovesToProcessing(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrigData')->willReturn('new');
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getEntityId')->willReturn(42);
        $order->method('getStoreId')->willReturn(self::STORE_ID);

        $event = $this->createMock(MagentoEvent::class);
        $event->method('getData')->with('order')->willReturn($order);

        $observerEvent = $this->createMock(Observer::class);
        $observerEvent->method('getEvent')->willReturn($event);

        $this->publisher->method('publishOrder')->willReturnCallback(
            function (
                $publishedOrder,
                string $eventIdHash,
                ?string $fbp = null,
                ?string $fbc = null,
                ?string $consent = null
            ): void {
                $this->published = true;
                $this->publishedConsent = $consent;
            }
        );

        $this->buildObserver()->execute($observerEvent);
    }

    private function buildObserver(): OrderStateTransitionObserver
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $row = $this->getMockBuilder(EventLog::class)
            ->disableOriginalConstructor()
            ->addMethods([
                'setOrderId',
                'setIncrementId',
                'setStateAtSend',
                'setEventIdHash',
                'setStatus',
                'setAttempts',
            ])
            ->getMock();
        foreach (
            [
                'setOrderId',
                'setIncrementId',
                'setStateAtSend',
                'setEventIdHash',
                'setStatus',
                'setAttempts',
            ] as $setter
        ) {
            $row->method($setter)->willReturnSelf();
        }

        $factory = $this->createMock(EventLogFactory::class);
        $factory->method('create')->willReturn($row);

        $repository = $this->createMock(EventLogRepositoryInterface::class);
        $repository->method('save')->willReturn($row);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new OrderStateTransitionObserver(
            $config,
            $factory,
            $repository,
            $this->publisher,
            new UuidV5Generator(),
            $this->cookieManager,
            $this->scopeConfig,
            $this->appState,
            $storeManager,
            new CookieRestrictionConsentResolver(),
            $this->createMock(LoggerInterface::class),
        );
    }
}
