<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Observer;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../magento-stubs.php';

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\Identity\BrowserIdentity;
use AxiTrace\Tracking\Model\Identity\BrowserIdentityCapture;
use AxiTrace\Tracking\Model\Identity\OrderBrowserIdentityStore;
use AxiTrace\Tracking\Observer\CaptureBrowserIdentityObserver;
use Magento\Framework\Event as MagentoEvent;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * At placement the shopper's identity is put on the order (persisted by the save
 * that follows), never overwritten, and a failure never breaks the checkout.
 */
class CaptureBrowserIdentityObserverTest extends TestCase
{
    private const VISITOR = '6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10';

    public function testCapturedIdentityIsStoredOnTheOrder(): void
    {
        $order = $this->order();

        $this->observer($this->captureReturning($this->identity()))->execute($this->event($order));

        $stored = json_decode((string) $order->getData(OrderBrowserIdentityStore::COLUMN), true);
        self::assertSame(self::VISITOR, $stored['visitor_id']);
        self::assertSame('Cj0KCQjw-gclid', $stored['gclid']);
        self::assertSame('Mozilla/5.0 Test', $stored['user_agent']);
    }

    public function testNotABrowserRequestStoresNothing(): void
    {
        $order = $this->order();

        $this->observer($this->captureReturning(null))->execute($this->event($order));

        self::assertNull($order->getData(OrderBrowserIdentityStore::COLUMN));
    }

    public function testAnIdentityAlreadyOnTheOrderIsNeverOverwritten(): void
    {
        $order = $this->order();
        $order->setData(OrderBrowserIdentityStore::COLUMN, '{"visitor_id":"first-visitor-0001"}');

        $this->observer($this->captureReturning($this->identity()))->execute($this->event($order));

        self::assertSame('{"visitor_id":"first-visitor-0001"}', $order->getData(OrderBrowserIdentityStore::COLUMN));
    }

    public function testDisabledModuleStoresNothing(): void
    {
        $order = $this->order();

        $this->observer($this->captureReturning($this->identity()), enabled: false)->execute($this->event($order));

        self::assertNull($order->getData(OrderBrowserIdentityStore::COLUMN));
    }

    public function testCaptureFailureIsLoggedAndNeverBreaksTheCheckout(): void
    {
        $order = $this->order();
        $capture = $this->createMock(BrowserIdentityCapture::class);
        $capture->method('capture')->willThrowException(new \RuntimeException('cookie jar exploded'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')->with(
            self::stringContains('RuntimeException: cookie jar exploded')
        );

        $this->observer($capture, logger: $logger)->execute($this->event($order));

        self::assertNull($order->getData(OrderBrowserIdentityStore::COLUMN));
    }

    private function identity(): BrowserIdentity
    {
        return BrowserIdentity::fromArray([
            'visitor_id' => self::VISITOR,
            'user_agent' => 'Mozilla/5.0 Test',
            'gclid'      => 'Cj0KCQjw-gclid',
        ]);
    }

    private function order(): Order
    {
        // getData/setData backed by a real array: what the observer sets must be readable
        // back, exactly as on a Magento order object.
        $bag = [];
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getData')->willReturnCallback(
            static function ($key = null) use (&$bag) {
                return $bag[$key] ?? null;
            }
        );
        $order->method('setData')->willReturnCallback(
            static function ($key, $value = null) use (&$bag, &$order) {
                $bag[$key] = $value;

                return $order;
            }
        );

        return $order;
    }

    private function event(Order $order): Observer
    {
        $event = $this->createMock(MagentoEvent::class);
        $event->method('getData')->with('order')->willReturn($order);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    private function captureReturning(?BrowserIdentity $identity): BrowserIdentityCapture
    {
        $capture = $this->createMock(BrowserIdentityCapture::class);
        $capture->method('capture')->willReturn($identity);

        return $capture;
    }

    private function observer(
        BrowserIdentityCapture $capture,
        bool $enabled = true,
        ?LoggerInterface $logger = null
    ): CaptureBrowserIdentityObserver {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new CaptureBrowserIdentityObserver(
            $config,
            $capture,
            new OrderBrowserIdentityStore($this->createMock(LoggerInterface::class)),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }
}
