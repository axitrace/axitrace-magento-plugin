<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Normalizer;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Normalizer\OrderEventNormalizer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * The normalizer writes the payload ingestion-api receives; `data.consent` is what
 * the workspace consent policy reads to decide whether this purchase may reach the
 * ad platforms.
 */
class OrderEventNormalizerTest extends TestCase
{
    public function testConsentIsWrittenIntoData(): void
    {
        $event = $this->normalizeWith('denied');

        self::assertSame('denied', $event['data']['consent']);
    }

    public function testGrantedConsentIsWrittenIntoData(): void
    {
        $event = $this->normalizeWith('granted');

        self::assertSame('granted', $event['data']['consent']);
    }

    public function testNoConsentLeavesTheKeyOut(): void
    {
        $event = $this->normalizeWith(null);

        self::assertArrayNotHasKey('consent', $event['data']);
    }

    public function testUnknownConsentValueIsNotForwarded(): void
    {
        $event = $this->normalizeWith('maybe');

        self::assertArrayNotHasKey('consent', $event['data']);
    }

    public function testPluginVersionIsReported(): void
    {
        $event = $this->normalizeWith(null);

        self::assertSame('0.2.0', $event['pluginVersion']);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeWith(?string $consent): array
    {
        $order = $this->createMock(Order::class);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getAllVisibleItems')->willReturn([]);
        $order->method('getGrandTotal')->willReturn(199.99);
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getRemoteIp')->willReturn('203.0.113.7');

        return (new OrderEventNormalizer())->normalize($order, 'hash-1', 'pk_test', null, null, $consent);
    }
}
