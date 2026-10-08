<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Normalizer;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Identity\BrowserIdentity;
use AxiTrace\Tracking\Model\Normalizer\OrderEventNormalizer;
use AxiTrace\Tracking\Model\Normalizer\OrderLineCostResolver;
use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;

/**
 * The normalizer writes the payload ingestion-api receives; `data.consent` is what
 * the workspace consent policy reads to decide whether this purchase may reach the
 * ad platforms, and `tax`, `shipping`, `taxesIncluded` and the per-line `unitCost`
 * and `externalId` are what AxiTrace computes the order's profit from.
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

        self::assertSame('0.4.0', $event['pluginVersion']);
    }

    public function testBrowserIdentityLinksThePurchaseToTheVisitor(): void
    {
        $browser = BrowserIdentity::fromArray([
            'visitor_id' => '6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10',
            'session_id' => '0b9a7c65-1d2e-4f30-8a9b-c1d2e3f4a5b6',
            'ip'         => '198.51.100.23',
            'user_agent' => 'Mozilla/5.0 Test',
            'fbp'        => 'fb.1.1700000000000.1234567890',
            'ttp'        => '01KCFX1BV9NB74R5ZE592YDTYN_.tt.1',
            'gclid'      => 'Cj0KCQjw-gclid',
            'ttclid'     => 'E.C.P.ttclid',
            'rdt_cid'    => 'rdt-click-1',
        ]);

        $event = $this->normalizer()->normalize($this->order([], 'EUR', 'EUR'), 'hash-1', 'pk_test', $browser);

        // userId / sessionId are what ingestion stores the purchase under (GeneratedEvent).
        self::assertSame('6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10', $event['userId']);
        self::assertSame('0b9a7c65-1d2e-4f30-8a9b-c1d2e3f4a5b6', $event['sessionId']);
        self::assertSame('198.51.100.23', $event['ip']);
        self::assertSame('Mozilla/5.0 Test', $event['userAgent']);
        self::assertSame('fb.1.1700000000000.1234567890', $event['data']['fbp']);
        self::assertSame('01KCFX1BV9NB74R5ZE592YDTYN_.tt.1', $event['data']['ttp']);
        self::assertSame('Cj0KCQjw-gclid', $event['data']['gclid']);
        self::assertSame('E.C.P.ttclid', $event['data']['ttclid']);
        self::assertSame('rdt-click-1', $event['data']['rdt_cid']);
    }

    public function testWithoutBrowserIdentityTheOrderIpIsKeptAndNoVisitorIsInvented(): void
    {
        $event = $this->normalizeWith(null);

        self::assertArrayNotHasKey('userId', $event);
        self::assertArrayNotHasKey('sessionId', $event);
        self::assertSame('203.0.113.7', $event['ip']);
        self::assertSame('', $event['userAgent']);
        self::assertArrayNotHasKey('gclid', $event['data']);
    }

    public function testTaxShippingAndTaxesIncludedAreSentInTheOrderCurrency(): void
    {
        $order = $this->order([], 'EUR', 'EUR');
        $order->method('getTaxAmount')->willReturn('37.4000');
        $order->method('getShippingInclTax')->willReturn('12.3000');

        $data = $this->normalizer()->normalize($order, 'hash-1', 'pk_test')['data'];

        self::assertSame(37.4, $data['tax']);
        self::assertSame(12.3, $data['shipping']);
        self::assertTrue($data['taxesIncluded']);
        self::assertSame(['amount' => 199.99, 'currency' => 'EUR'], $data['revenue']);
    }

    public function testUnitCostFromTheOrderItemBaseCost(): void
    {
        $item = $this->item(productId: 7, baseCost: '18.2500');
        $order = $this->order([$item], 'EUR', 'EUR');

        $line = $this->normalizer()->normalize($order, 'hash-1', 'pk_test', includeCosts: true)['data']['products'][0];

        self::assertSame(['amount' => 18.25, 'currency' => 'EUR'], $line['unitCost']);
        self::assertSame('magento:7', $line['externalId']);
    }

    public function testUnitCostFallsBackToTheProductCostAttribute(): void
    {
        $product = $this->product('9.99');
        $item = $this->item(productId: 8, baseCost: null, product: $product);
        $order = $this->order([$item], 'EUR', 'EUR');

        $line = $this->normalizer()->normalize($order, 'hash-1', 'pk_test', includeCosts: true)['data']['products'][0];

        self::assertSame(['amount' => 9.99, 'currency' => 'EUR'], $line['unitCost']);
    }

    public function testConfigurableLineUsesTheChildProductForCostAndExternalId(): void
    {
        $child = $this->item(productId: 1201, baseCost: '30.0000');
        $parent = $this->item(productId: 1200, baseCost: null, children: [$child]);
        $order = $this->order([$parent], 'EUR', 'EUR');

        $line = $this->normalizer()->normalize($order, 'hash-1', 'pk_test', includeCosts: true)['data']['products'][0];

        self::assertSame('1200', $line['productId']);
        self::assertSame('magento:1201', $line['externalId']);
        self::assertSame(['amount' => 30.0, 'currency' => 'EUR'], $line['unitCost']);
    }

    public function testLineWithoutAnyCostHasNoUnitCost(): void
    {
        $product = $this->product('0');
        $item = $this->item(productId: 9, baseCost: '0.0000', product: $product);
        $order = $this->order([$item], 'EUR', 'EUR');

        $line = $this->normalizer()->normalize($order, 'hash-1', 'pk_test', includeCosts: true)['data']['products'][0];

        self::assertArrayNotHasKey('unitCost', $line);
        self::assertSame('magento:9', $line['externalId']);
    }

    public function testNoUnitCostWithoutTheSecretKey(): void
    {
        $item = $this->item(productId: 7, baseCost: '18.2500');
        $order = $this->order([$item], 'EUR', 'EUR');

        $line = $this->normalizer()->normalize($order, 'hash-1', 'pk_test', includeCosts: false)['data']['products'][0];

        self::assertArrayNotHasKey('unitCost', $line);
        self::assertSame('magento:7', $line['externalId']);
    }

    public function testNoUnitCostWhenTheBaseCurrencyDiffersFromTheOrderCurrency(): void
    {
        $item = $this->item(productId: 7, baseCost: '18.2500');
        $order = $this->order([$item], 'USD', 'EUR');

        $line = $this->normalizer()->normalize($order, 'hash-1', 'pk_test', includeCosts: true)['data']['products'][0];

        self::assertArrayNotHasKey('unitCost', $line);
        self::assertSame('USD', $line['currency']);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeWith(?string $consent): array
    {
        return $this->normalizer()->normalize($this->order([], 'EUR', 'EUR'), 'hash-1', 'pk_test', null, $consent);
    }

    private function normalizer(): OrderEventNormalizer
    {
        return new OrderEventNormalizer(new OrderLineCostResolver());
    }

    /**
     * @param list<OrderItem> $items
     */
    private function order(array $items, string $orderCurrency, string $baseCurrency): Order&\PHPUnit\Framework\MockObject\MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getOrderCurrencyCode')->willReturn($orderCurrency);
        $order->method('getBaseCurrencyCode')->willReturn($baseCurrency);
        $order->method('getAllVisibleItems')->willReturn($items);
        $order->method('getGrandTotal')->willReturn(199.99);
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getRemoteIp')->willReturn('203.0.113.7');

        return $order;
    }

    private function product(string $cost): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(
            static fn (?string $key = null) => $key === 'cost' ? $cost : null
        );

        return $product;
    }

    /**
     * @param list<OrderItem> $children
     */
    private function item(int $productId, ?string $baseCost, ?Product $product = null, array $children = []): OrderItem
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getSku')->willReturn('SKU-' . $productId);
        $item->method('getName')->willReturn('Product ' . $productId);
        $item->method('getQtyOrdered')->willReturn(2);
        $item->method('getPrice')->willReturn(50.0);
        $item->method('getBaseCost')->willReturn($baseCost);
        $item->method('getProduct')->willReturn($product);
        $item->method('getChildrenItems')->willReturn($children);

        return $item;
    }
}
