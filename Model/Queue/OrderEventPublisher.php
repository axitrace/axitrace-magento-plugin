<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Publishes an order-placed message onto the `axitrace.order.placed` topic.
 *
 * Payload is a JSON-encoded array containing only what the consumer needs to
 * rebuild the event — the order is re-fetched from the repository on the
 * consumer side, so we never trust mutable state across the queue boundary.
 */
class OrderEventPublisher
{
    private const TOPIC = 'axitrace.order.placed';

    public function __construct(
        private readonly PublisherInterface $publisher,
    ) {
    }

    public function publishOrder(OrderInterface $order, string $eventIdHash): void
    {
        $payload = [
            'order_id'      => (int) $order->getEntityId(),
            'increment_id'  => (string) $order->getIncrementId(),
            'store_id'      => (int) $order->getStoreId(),
            'event_id_hash' => $eventIdHash,
            'state'         => (string) $order->getState(),
        ];

        $this->publisher->publish(self::TOPIC, (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
