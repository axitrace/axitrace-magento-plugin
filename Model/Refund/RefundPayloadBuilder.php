<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Refund;

use AxiTrace\Tracking\Model\Normalizer\OrderLineCostResolver;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;

/**
 * Builds the `POST /v1/refund` body for a credit memo or an order cancellation.
 *
 *   {
 *     orderId, refundId, refundedAt (ISO 8601 UTC), amount, currency,
 *     isCancellation, lines: [{ sku, externalId, quantity, amount }]
 *   }
 *
 * Consistency with the purchase event (OrderEventNormalizer):
 *   - `orderId` is the order entity id, exactly the `orderId` of the purchase.
 *   - Amounts are in the ORDER currency, like the purchase `revenue` (grand total):
 *     the credit memo's `grand_total`, or the order's `total_canceled`.
 *   - `externalId` comes from the same OrderLineCostResolver, so a refunded line names
 *     the product its purchase line named.
 *
 * Refund ids: a credit memo uses its own entity id; a cancellation, which has no
 * document of its own and happens at most once per order, uses `cancel-<order id>`.
 *
 * Cancellations (`order_cancel_after`), as AxiTrace reverses them:
 *   - FULL (the canceled amount covers the whole grand total, nothing was paid):
 *     `isCancellation = true` with NO lines, which AxiTrace reverses as the whole
 *     order. No line is guessed from per-item counters.
 *   - PARTIAL (some items invoiced, the rest canceled): `isCancellation = true` with
 *     exactly the canceled lines, which AxiTrace reverses line by line.
 *   - PARTIAL with no canceled product line (e.g. only the uninvoiced shipping was
 *     canceled): sent as an amount-only refund (`isCancellation = false`, no lines),
 *     because a cancellation without lines would reverse the whole order.
 * Every amount is gross (tax included) in the order currency: Magento's
 * `total_canceled` is grand total minus total paid, and a line is its row total
 * including tax minus its discount, pro rata to the canceled quantity.
 */
class RefundPayloadBuilder
{
    public const CANCELLATION_ID_PREFIX = 'cancel-';

    public function __construct(
        private readonly OrderLineCostResolver $lineCostResolver,
    ) {
    }

    /**
     * @return array<string, mixed>|null Null when the credit memo refunds nothing.
     */
    public function fromCreditmemo(CreditmemoInterface $creditmemo, OrderInterface $order, string $now): ?array
    {
        $refundId = (string) $creditmemo->getEntityId();
        $amount = $this->money($creditmemo->getGrandTotal());
        if ($refundId === '' || $amount <= 0.0) {
            return null;
        }

        $lines = [];
        foreach ((array) $creditmemo->getItems() as $item) {
            if (!$item instanceof CreditmemoItemInterface) {
                continue;
            }

            $orderItem = $item instanceof CreditmemoItem ? $item->getOrderItem() : null;
            // A configurable product is refunded as a parent line plus a zero-priced
            // child line; the parent carries the amounts, the resolver names the child.
            if ($orderItem instanceof OrderItemInterface && $orderItem->getParentItem() !== null) {
                continue;
            }

            $quantity = (float) $item->getQty();
            if ($quantity <= 0.0) {
                continue;
            }

            $lines[] = $this->line(
                (string) $item->getSku(),
                $orderItem instanceof OrderItemInterface ? $this->lineCostResolver->externalId($orderItem) : '',
                $quantity,
                (float) $item->getRowTotalInclTax() - (float) $item->getDiscountAmount(),
            );
        }

        return [
            'orderId'        => (string) $order->getEntityId(),
            'refundId'       => $refundId,
            'refundedAt'     => $this->isoUtc($creditmemo->getCreatedAt(), $now),
            'amount'         => $amount,
            'currency'       => (string) $order->getOrderCurrencyCode(),
            'isCancellation' => false,
            'lines'          => $lines,
        ];
    }

    /**
     * @return array<string, mixed>|null Null when the cancellation released no amount.
     */
    public function fromCancellation(OrderInterface $order, string $now): ?array
    {
        $amount = $this->money($order->getTotalCanceled());
        $orderId = (string) $order->getEntityId();
        if ($orderId === '' || $amount <= 0.0) {
            return null;
        }

        $isFull = round($amount, 2) >= round($this->money($order->getGrandTotal()), 2);
        $lines = $isFull ? [] : $this->canceledLines($order);

        return [
            'orderId'        => $orderId,
            'refundId'       => self::CANCELLATION_ID_PREFIX . $orderId,
            'refundedAt'     => $this->isoUtc(null, $now),
            'amount'         => $amount,
            'currency'       => (string) $order->getOrderCurrencyCode(),
            // A partial cancellation that names no product line must not read as a
            // whole-order reversal: it goes as an amount-only refund instead.
            'isCancellation' => $isFull || $lines !== [],
            'lines'          => $lines,
        ];
    }

    /**
     * The product lines a partial cancellation released, gross, pro rata to the
     * canceled quantity.
     *
     * @return list<array<string, mixed>>
     */
    private function canceledLines(OrderInterface $order): array
    {
        $lines = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }

            $canceled = (float) $item->getQtyCanceled();
            $ordered = (float) $item->getQtyOrdered();
            if ($canceled <= 0.0 || $ordered <= 0.0) {
                continue;
            }

            $rowGross = (float) $item->getRowTotalInclTax() - (float) $item->getDiscountAmount();

            $lines[] = $this->line(
                (string) $item->getSku(),
                $this->lineCostResolver->externalId($item),
                $canceled,
                $rowGross * min(1.0, $canceled / $ordered),
            );
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $sku, string $externalId, float $quantity, float $amount): array
    {
        $line = [
            'sku'      => $sku,
            'quantity' => $quantity,
            'amount'   => max(0.0, round($amount, 4)),
        ];
        if ($externalId !== '') {
            $line['externalId'] = $externalId;
        }

        return $line;
    }

    private function money(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 4) : 0.0;
    }

    /**
     * Magento stores `created_at` in UTC as `Y-m-d H:i:s`. A credit memo saved a
     * moment ago may not have it loaded yet, so $now (UTC) is the fallback.
     */
    private function isoUtc(mixed $storedUtc, string $now): string
    {
        $utc = new \DateTimeZone('UTC');

        if (is_string($storedUtc) && $storedUtc !== '') {
            try {
                return (new \DateTimeImmutable($storedUtc, $utc))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
            } catch (\Exception) {
                // Unparseable stored value: use the moment of sending below.
            }
        }

        return (new \DateTimeImmutable($now, $utc))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
    }
}
