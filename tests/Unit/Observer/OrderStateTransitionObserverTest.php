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
use AxiTrace\Tracking\Model\Identity\BrowserIdentity;
use AxiTrace\Tracking\Model\Identity\BrowserIdentityCapture;
use AxiTrace\Tracking\Model\Identity\BrowserIdentityExtractor;
use AxiTrace\Tracking\Model\Identity\OrderBrowserIdentityStore;
use AxiTrace\Tracking\Model\Queue\OrderEventPublisher;
use AxiTrace\Tracking\Observer\OrderStateTransitionObserver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State;
use Magento\Framework\Event as MagentoEvent;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\Header as HttpHeader;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The observer is the request-scoped capture point for consent: it must hand the
 * Cookie Restriction Mode decision to OrderEventPublisher::publishOrder() and must
 * stamp nothing outside the frontend area. For the browser identity it publishes
 * what was stored on the order at placement, and falls back to the current request
 * only when that request is the shopper's browser - never an admin invoice.
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
    private ?BrowserIdentity $publishedBrowser = null;
    private bool $published = false;

    /** @var array<string, string> Cookies of the request the observer runs in. */
    private array $cookies = [];

    /** JSON stored in sales_order.axitrace_browser_identity, or null. */
    private ?string $storedIdentity = null;

    protected function setUp(): void
    {
        $this->publishedConsent = null;
        $this->publishedBrowser = null;
        $this->published = false;
        $this->cookies = [];
        $this->storedIdentity = null;

        $this->publisher     = $this->createMock(OrderEventPublisher::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->scopeConfig   = $this->createMock(ScopeConfigInterface::class);
        $this->appState      = $this->createMock(State::class);

        $this->cookieManager->method('getCookie')->willReturnCallback(
            fn (string $name): ?string => $this->cookies[$name] ?? null
        );
    }

    public function testIdentityStoredAtPlacementIsPublishedEvenFromAnAdminInvoice(): void
    {
        $this->givenRestrictionMode(false);
        $this->givenAreaCode('adminhtml');
        $this->storedIdentity = '{"visitor_id":"6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10","gclid":"Cj0KCQjw-gclid"}';

        $this->whenOrderMovesToProcessing();

        self::assertNotNull($this->publishedBrowser);
        self::assertSame('6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10', $this->publishedBrowser->visitorId());
        self::assertSame('Cj0KCQjw-gclid', $this->publishedBrowser->signals()['gclid']);
    }

    public function testAdminInvoiceNeverAttachesTheMerchantsOwnCookies(): void
    {
        // Before 0.4.0 the observer read _fbp/_fbc from whatever request it ran in, so
        // the merchant invoicing a bank-transfer order sent their own Meta cookies.
        $this->givenRestrictionMode(false);
        $this->givenAreaCode('adminhtml');
        $this->cookies = [
            '_fbp'   => 'fb.1.1700000000000.1111111111',
            '_fbc'   => 'fb.1.1700000000000.MerchantClick',
            'vt_vid' => 'merchant-visitor-0001',
        ];

        $this->whenOrderMovesToProcessing();

        self::assertTrue($this->published);
        self::assertNull($this->publishedBrowser);
    }

    public function testOrderWithoutStoredIdentityFallsBackToTheShoppersRequest(): void
    {
        $this->givenRestrictionMode(false);
        $this->givenAreaCode('frontend');
        $this->cookies = [
            'vt_vid' => '6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10',
            '_fbp'   => 'fb.1.1700000000000.1234567890',
        ];

        $this->whenOrderMovesToProcessing();

        self::assertNotNull($this->publishedBrowser);
        self::assertSame('6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10', $this->publishedBrowser->visitorId());
        self::assertSame('fb.1.1700000000000.1234567890', $this->publishedBrowser->signals()['fbp']);
        self::assertSame('Mozilla/5.0 Test', $this->publishedBrowser->userAgent());
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
        if ($value !== null) {
            $this->cookies[CookieRestrictionConsentResolver::COOKIE_NAME] = $value;
        }
    }

    private function whenOrderMovesToProcessing(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrigData')->willReturn('new');
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getEntityId')->willReturn(42);
        $order->method('getStoreId')->willReturn(self::STORE_ID);
        $order->method('getData')->willReturnCallback(
            fn ($key = null) => $key === OrderBrowserIdentityStore::COLUMN ? $this->storedIdentity : null
        );

        $event = $this->createMock(MagentoEvent::class);
        $event->method('getData')->with('order')->willReturn($order);

        $observerEvent = $this->createMock(Observer::class);
        $observerEvent->method('getEvent')->willReturn($event);

        $this->publisher->method('publishOrder')->willReturnCallback(
            function (
                $publishedOrder,
                string $eventIdHash,
                ?BrowserIdentity $browser = null,
                ?string $consent = null
            ): void {
                $this->published = true;
                $this->publishedBrowser = $browser;
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
            new OrderBrowserIdentityStore($this->createMock(LoggerInterface::class)),
            $this->realCapture(),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * The real capture guard on top of this test's area code and cookies, for a
     * request that carries a Cookie header (any browser or admin session does).
     */
    private function realCapture(): BrowserIdentityCapture
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getServerValue')->willReturnCallback(
            static fn (string $name) => $name === 'HTTP_COOKIE' ? 'PHPSESSID=abc' : null
        );
        $request->method('getQueryValue')->willReturn(null);

        $remote = $this->createMock(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('198.51.100.23');

        $header = $this->createMock(HttpHeader::class);
        $header->method('getHttpUserAgent')->willReturn('Mozilla/5.0 Test');

        return new BrowserIdentityCapture(
            $this->appState,
            $this->cookieManager,
            $request,
            $remote,
            $header,
            new BrowserIdentityExtractor(),
        );
    }
}
