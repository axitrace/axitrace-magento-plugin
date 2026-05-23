<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\ViewModel;

use AxiTrace\Tracking\Model\EventId\UuidV5Generator;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * View model for the order confirmation pixel. Reads the last order via the
 * checkout session and produces the data the SDK needs to fire a deduped
 * client-side `transaction.charge` event.
 *
 * The deterministic event_id is computed via UuidV5Generator (same algorithm
 * the server-side observer + Go ingestion-api use) so client and server emit
 * identical ids — required for Facebook CAPI / TikTok dedupe.
 */
class OrderConfirmationContext implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly UuidV5Generator $uuidGenerator,
    ) {
    }

    public function hasOrder(): bool
    {
        return $this->checkoutSession->getLastRealOrder() !== null
            && $this->checkoutSession->getLastRealOrder()->getId() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderPayload(): array
    {
        $order = $this->checkoutSession->getLastRealOrder();
        if ($order === null || $order->getId() === null) {
            return [];
        }

        $incrementId = (string) $order->getIncrementId();
        $eventId = $incrementId !== '' ? $this->uuidGenerator->forOrder($incrementId) : '';
        $currency = (string) $order->getOrderCurrencyCode();

        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'productId' => (string) $item->getProductId(),
                'sku'       => (string) $item->getSku(),
                'name'      => (string) $item->getName(),
                'quantity'  => (float) $item->getQtyOrdered(),
                'price'     => (float) $item->getPrice(),
                'currency'  => $currency,
            ];
        }

        return [
            'event_id'   => $eventId,
            'orderId'    => (string) $order->getEntityId(),
            'incrementId'=> $incrementId,
            'value'      => (float) $order->getGrandTotal(),
            'currency'   => $currency,
            'items'      => $items,
            'email'      => (string) $order->getCustomerEmail(),
        ];
    }
}
