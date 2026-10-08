<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Identity;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Identity\BrowserIdentityExtractor;
use AxiTrace\Tracking\Model\Identity\OrderBrowserIdentityStore;
use AxiTrace\Tracking\Model\Normalizer\OrderEventNormalizer;
use AxiTrace\Tracking\Model\Normalizer\OrderLineCostResolver;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The path that matters in production for the click ids added in web SDK 0.24.0:
 * read in the request that places the order, stored in the order column, read back
 * by the queue consumer and sent as flat `data.<key>` - the keys the event worker
 * reads (`IntegrationForwardingService`).
 */
class ClickIdRoundTripTest extends TestCase
{
    private const NOW_MS = 1_790_000_000_000;

    public function testNewClickIdsAreStoredOnTheOrderAndSentAsFlatDataKeys(): void
    {
        $fresh = 'v2|' . (self::NOW_MS - 86_400_000) . '|';
        $cookies = [
            '_axi_msclkid'   => $fresh . 'msclkid-rt',
            '_twclid'        => '{"twclid":"twclid-vendor-rt"}',
            '_axi_epik'      => $fresh . 'epik-rt',
            'li_fat_id'      => 'li-fat-vendor-rt',
        ];
        $query = ['ScCid' => 'sccid-url-rt'];

        $captured = (new BrowserIdentityExtractor())->extract(
            static fn (string $name): ?string => $cookies[$name] ?? null,
            static fn (string $name): ?string => $query[$name] ?? null,
            null,
            null,
            self::NOW_MS
        );

        $order = $this->order();
        $store = new OrderBrowserIdentityStore($this->createMock(LoggerInterface::class));
        $store->write($order, $captured);
        $readBack = $store->read($order);
        self::assertNotNull($readBack);

        $normalizer = new OrderEventNormalizer($this->createMock(OrderLineCostResolver::class));
        $data = $normalizer->normalize($order, 'hash-rt', 'pk_test', $readBack)['data'];

        self::assertSame('msclkid-rt', $data['msclkid'] ?? null);
        self::assertSame('twclid-vendor-rt', $data['twclid'] ?? null);
        self::assertSame('epik-rt', $data['epik'] ?? null);
        self::assertSame('li-fat-vendor-rt', $data['li_fat_id'] ?? null);
        self::assertSame('sccid-url-rt', $data['sccid'] ?? null);
    }

    private function order(): Order
    {
        $bag = [];
        $order = $this->createMock(Order::class);
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
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getAllVisibleItems')->willReturn([]);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getBaseCurrencyCode')->willReturn('EUR');
        $order->method('getGrandTotal')->willReturn(100.0);

        return $order;
    }
}
