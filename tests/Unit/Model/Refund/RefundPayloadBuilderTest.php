<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Refund;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Normalizer\OrderLineCostResolver;
use AxiTrace\Tracking\Model\Refund\RefundPayloadBuilder;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;

/**
 * The `POST /v1/refund` body: `orderId` must equal the purchase's `orderId`, amounts
 * are in the order currency like the purchase revenue, and each line names the
 * product with the same `externalId` its purchase line carried.
 */
class RefundPayloadBuilderTest extends TestCase
{
    private const NOW = '2026-10-02 10:15:00';

    public function testCreditmemoPayload(): void
    {
        $simpleOrderItem = $this->orderItem(productId: 7);
        $configurableChild = $this->orderItem(productId: 1201);
        $configurableParent = $this->orderItem(productId: 1200, children: [$configurableChild]);
        $childOrderItem = $this->orderItem(productId: 1201, parent: $configurableParent);

        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getEntityId')->willReturn(77);
        $creditmemo->method('getGrandTotal')->willReturn('80.5000');
        $creditmemo->method('getCreatedAt')->willReturn('2026-10-01 08:30:00');
        $creditmemo->method('getItems')->willReturn([
            $this->creditmemoItem('SKU-7', 1, '50.0000', '5.0000', $simpleOrderItem),
            $this->creditmemoItem('SKU-1201', 1, '35.5000', '0', $configurableParent),
            $this->creditmemoItem('SKU-1201', 1, '0', '0', $childOrderItem),
            $this->creditmemoItem('SKU-NOT-REFUNDED', 0, '0', '0', $this->orderItem(productId: 9)),
        ]);

        $payload = $this->builder()->fromCreditmemo($creditmemo, $this->order(), self::NOW);

        self::assertSame([
            'orderId'        => '42',
            'refundId'       => '77',
            'refundedAt'     => '2026-10-01T08:30:00Z',
            'amount'         => 80.5,
            'currency'       => 'EUR',
            'isCancellation' => false,
            'lines'          => [
                ['sku' => 'SKU-7', 'quantity' => 1.0, 'amount' => 45.0, 'externalId' => 'magento:7'],
                ['sku' => 'SKU-1201', 'quantity' => 1.0, 'amount' => 35.5, 'externalId' => 'magento:1201'],
            ],
        ], $payload);
    }

    public function testCreditmemoWithoutCreatedAtUsesNow(): void
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getEntityId')->willReturn(78);
        $creditmemo->method('getGrandTotal')->willReturn(10);
        $creditmemo->method('getCreatedAt')->willReturn(null);
        $creditmemo->method('getItems')->willReturn([]);

        $payload = $this->builder()->fromCreditmemo($creditmemo, $this->order(), self::NOW);

        self::assertSame('2026-10-02T10:15:00Z', $payload['refundedAt']);
        self::assertSame([], $payload['lines']);
    }

    public function testZeroCreditmemoIsNotSent(): void
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getEntityId')->willReturn(79);
        $creditmemo->method('getGrandTotal')->willReturn('0.0000');

        self::assertNull($this->builder()->fromCreditmemo($creditmemo, $this->order(), self::NOW));
    }

    public function testCancellationPayload(): void
    {
        $item = $this->orderItem(productId: 7, qtyOrdered: 4, qtyCanceled: 2, rowTotalInclTax: '200.0000', discount: '20.0000');
        $notCanceled = $this->orderItem(productId: 8, qtyOrdered: 1, qtyCanceled: 0, rowTotalInclTax: '30.0000');

        $order = $this->order([$item, $notCanceled], totalCanceled: '90.0000', grandTotal: '240.0000');

        $payload = $this->builder()->fromCancellation($order, self::NOW);

        self::assertSame([
            'orderId'        => '42',
            'refundId'       => 'cancel-42',
            'refundedAt'     => '2026-10-02T10:15:00Z',
            'amount'         => 90.0,
            'currency'       => 'EUR',
            'isCancellation' => true,
            'lines'          => [
                ['sku' => 'SKU-7', 'quantity' => 2.0, 'amount' => 90.0, 'externalId' => 'magento:7'],
            ],
        ], $payload);
    }

    public function testFullCancellationSendsNoLinesSoTheWholeOrderIsReversed(): void
    {
        // Line counters that do not add up (a child line, a partially loaded item)
        // must not turn a full cancellation into a partial one.
        $item = $this->orderItem(productId: 7, qtyOrdered: 2, qtyCanceled: 2, rowTotalInclTax: '100.0000');
        $notLoaded = $this->orderItem(productId: 8, qtyOrdered: 1, qtyCanceled: 0, rowTotalInclTax: '30.0000');

        $payload = $this->builder()->fromCancellation(
            $this->order([$item, $notLoaded], totalCanceled: '139.9000', grandTotal: '139.9000'),
            self::NOW,
        );

        self::assertNotNull($payload);
        self::assertTrue($payload['isCancellation']);
        self::assertSame(139.9, $payload['amount'], 'gross grand total, shipping included');
        self::assertSame([], $payload['lines']);
    }

    public function testPartialCancellationWithoutAnyCanceledLineIsAnAmountOnlyRefund(): void
    {
        // Every item invoiced, only the uninvoiced shipping canceled: a cancellation
        // without lines would reverse the whole order.
        $invoiced = $this->orderItem(productId: 7, qtyOrdered: 1, qtyCanceled: 0, rowTotalInclTax: '100.0000');

        $payload = $this->builder()->fromCancellation(
            $this->order([$invoiced], totalCanceled: '9.9000', grandTotal: '109.9000'),
            self::NOW,
        );

        self::assertNotNull($payload);
        self::assertFalse($payload['isCancellation']);
        self::assertSame(9.9, $payload['amount']);
        self::assertSame([], $payload['lines']);
        self::assertSame('cancel-42', $payload['refundId']);
    }

    public function testCancellationWithNothingCanceledIsNotSent(): void
    {
        self::assertNull($this->builder()->fromCancellation($this->order([], totalCanceled: null), self::NOW));
    }

    private function builder(): RefundPayloadBuilder
    {
        return new RefundPayloadBuilder(new OrderLineCostResolver());
    }

    /**
     * @param list<OrderItem> $items
     */
    private function order(array $items = [], ?string $totalCanceled = null, ?string $grandTotal = null): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getAllVisibleItems')->willReturn($items);
        $order->method('getTotalCanceled')->willReturn($totalCanceled);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getIncrementId')->willReturn('000000042');

        return $order;
    }

    /**
     * @param list<OrderItem> $children
     */
    private function orderItem(
        int $productId,
        array $children = [],
        ?OrderItem $parent = null,
        float $qtyOrdered = 1,
        float $qtyCanceled = 0,
        string $rowTotalInclTax = '0',
        string $discount = '0',
    ): OrderItem {
        $item = $this->createMock(OrderItem::class);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getSku')->willReturn('SKU-' . $productId);
        $item->method('getChildrenItems')->willReturn($children);
        $item->method('getParentItem')->willReturn($parent);
        $item->method('getQtyOrdered')->willReturn($qtyOrdered);
        $item->method('getQtyCanceled')->willReturn($qtyCanceled);
        $item->method('getRowTotalInclTax')->willReturn($rowTotalInclTax);
        $item->method('getDiscountAmount')->willReturn($discount);

        return $item;
    }

    private function creditmemoItem(
        string $sku,
        float $qty,
        string $rowTotalInclTax,
        string $discount,
        OrderItem $orderItem,
    ): CreditmemoItem {
        $item = $this->createMock(CreditmemoItem::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getRowTotalInclTax')->willReturn($rowTotalInclTax);
        $item->method('getDiscountAmount')->willReturn($discount);
        $item->method('getOrderItem')->willReturn($orderItem);

        return $item;
    }
}
