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

    /**
     * @param string|null $fbp Validated Meta Browser ID cookie (_fbp), captured at request
     *                         time by OrderStateTransitionObserver. Null when absent/invalid.
     * @param string|null $fbc Validated Meta Click ID cookie (_fbc), same capture point.
     */
    public function publishOrder(
        OrderInterface $order,
        string $eventIdHash,
        ?string $fbp = null,
        ?string $fbc = null,
    ): void {
        $payload = [
            'order_id'      => (int) $order->getEntityId(),
            'increment_id'  => (string) $order->getIncrementId(),
            'store_id'      => (int) $order->getStoreId(),
            'event_id_hash' => $eventIdHash,
            'state'         => (string) $order->getState(),
        ];

        // Omitted entirely when absent so consumers of older messages (and stores without
        // their own Meta browser pixel) see exactly today's payload shape.
        if ($fbp !== null) {
            $payload['fbp'] = $fbp;
        }
        if ($fbc !== null) {
            $payload['fbc'] = $fbc;
        }

        $this->publisher->publish(self::TOPIC, (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
