<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\ViewModel;

require_once __DIR__ . '/../magento-stubs.php';

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\EventId\UuidV5Generator;
use AxiTrace\Tracking\ViewModel\OrderConfirmationContext;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * AxiTrace keeps the first purchase per event id. With the secret key the server-side
 * purchase carries the product costs, so the browser must not send a copy first.
 */
class OrderConfirmationContextTest extends TestCase
{
    public function testBrowserPurchaseIsSentWithoutASecretKey(): void
    {
        self::assertTrue($this->context('')->hasOrder());
    }

    public function testBrowserPurchaseIsNotSentWhileTheSecretKeyIsConfigured(): void
    {
        self::assertFalse($this->context('sk_live_secret')->hasOrder());
    }

    private function context(string $secretKey): OrderConfirmationContext
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $session = $this->createMock(Session::class);
        $session->method('getLastRealOrder')->willReturn($order);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getSecretKey')->willReturn($secretKey);

        return new OrderConfirmationContext($session, new UuidV5Generator(), $config);
    }
}
