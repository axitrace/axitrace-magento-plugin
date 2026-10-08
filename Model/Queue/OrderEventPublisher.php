<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Queue;

use AxiTrace\Tracking\Model\Consent\CookieRestrictionConsentResolver;
use AxiTrace\Tracking\Model\Identity\BrowserIdentity;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Publishes an order-placed message onto the `axitrace.order.placed` topic.
 *
 * Payload is a JSON-encoded array containing only what the consumer needs to
 * rebuild the event - the order is re-fetched from the repository on the
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
     * @param BrowserIdentity|null $browser The shopper's browser identity (visitor/session
     *                                      ids, IP, User-Agent, browser and click ids),
     *                                      as resolved by OrderStateTransitionObserver.
     *                                      Null or empty when there is none.
     * @param string|null $consent The visitor's Cookie Restriction Mode decision
     *                             ('granted' / 'denied'), captured by the observer.
     *                             Null when this request states nothing about consent.
     */
    public function publishOrder(
        OrderInterface $order,
        string $eventIdHash,
        ?BrowserIdentity $browser = null,
        ?string $consent = null,
    ): void {
        $payload = [
            'order_id'      => (int) $order->getEntityId(),
            'increment_id'  => (string) $order->getIncrementId(),
            'store_id'      => (int) $order->getStoreId(),
            'event_id_hash' => $eventIdHash,
            'state'         => (string) $order->getState(),
        ];

        // Omitted entirely when absent. The consumer also reads the identity stored on
        // the order, so a message without it (the retry cron re-publishes without one)
        // still sends the purchase with the shopper's identity.
        if ($browser !== null && !$browser->isEmpty()) {
            $payload['browser'] = $browser->toArray();
        }

        // Same rule for the consent state: a store with Cookie Restriction Mode off,
        // and every non-frontend context, publish exactly today's payload shape.
        if (
            $consent === CookieRestrictionConsentResolver::DECISION_GRANTED
            || $consent === CookieRestrictionConsentResolver::DECISION_DENIED
        ) {
            $payload['consent'] = $consent;
        }

        $this->publisher->publish(self::TOPIC, (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
