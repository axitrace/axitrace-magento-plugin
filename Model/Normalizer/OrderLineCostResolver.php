<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Normalizer;

use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Product identity and unit cost of one visible order line.
 *
 * Shared by the purchase normaliser and the refund payload builder so that a refund
 * line names the product with exactly the `externalId` its purchase line carried.
 *
 * Identity: a configurable product is ordered as a visible parent line plus one hidden
 * child line holding the simple product that was actually bought. The child is the
 * product with its own cost (the Magento equivalent of a Shopify variant or a
 * WooCommerce variation), so it names the line: `magento:<child product id>`. Every
 * other line uses its own product id.
 *
 * Cost: the order item's `base_cost` (copied from the product's `cost` attribute when
 * the order was placed), then the product's current `cost` attribute. Both are in the
 * store's BASE currency; the caller decides whether that currency may be sent. A zero,
 * negative or missing cost counts as "no cost": a cost of zero would report the whole
 * line as profit, so it is better to let AxiTrace fall back to its own catalog or
 * default margin.
 */
class OrderLineCostResolver
{
    public const EXTERNAL_ID_PREFIX = 'magento:';

    public function externalId(OrderItemInterface $item): string
    {
        $productId = (string) $this->costBearingItem($item)->getProductId();

        return $productId !== '' && $productId !== '0' ? self::EXTERNAL_ID_PREFIX . $productId : '';
    }

    /**
     * Unit cost in the store's base currency, or null when the line has none.
     */
    public function baseUnitCost(OrderItemInterface $item): ?float
    {
        $costItem = $this->costBearingItem($item);

        $cost = $this->positive($costItem->getBaseCost());
        if ($cost === null && $costItem !== $item) {
            $cost = $this->positive($item->getBaseCost());
        }
        if ($cost !== null) {
            return $cost;
        }

        return $this->productCost($costItem) ?? ($costItem !== $item ? $this->productCost($item) : null);
    }

    /**
     * The order item that carries the bought product: the single child of a
     * configurable line, otherwise the line itself.
     */
    private function costBearingItem(OrderItemInterface $item): OrderItemInterface
    {
        if (!$item instanceof OrderItem) {
            return $item;
        }

        $children = $item->getChildrenItems();
        if (is_array($children) && count($children) === 1) {
            $child = reset($children);
            if ($child instanceof OrderItemInterface) {
                return $child;
            }
        }

        return $item;
    }

    private function productCost(OrderItemInterface $item): ?float
    {
        if (!$item instanceof OrderItem) {
            return null;
        }

        $product = $item->getProduct();
        if ($product === null) {
            return null;
        }

        return $this->positive($product->getData('cost'));
    }

    private function positive(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount > 0 ? $amount : null;
    }
}
