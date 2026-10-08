<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Identity;

use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the shopper's browser identity on the order itself, in the
 * `sales_order.axitrace_browser_identity` column (JSON, see etc/db_schema.xml).
 *
 * The identity is captured while the order is placed, in the shopper's own request,
 * but the purchase is sent later: when the order reaches `processing` (often in an
 * admin invoice or a payment webhook) and from the asynchronous queue consumer. The
 * order row is the one place every one of those contexts can read it back from,
 * including the retry cron that re-publishes a failed purchase.
 */
class OrderBrowserIdentityStore
{
    public const COLUMN = 'axitrace_browser_identity';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The identity stored on the order, or null when none was stored (an order placed
     * in the admin, through a server-to-server API, or before module 0.4.0).
     */
    public function read(OrderInterface $order): ?BrowserIdentity
    {
        if (!method_exists($order, 'getData')) {
            return null;
        }

        $raw = $order->getData(self::COLUMN);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                'AxiTrace: unreadable browser identity on order ' . (string) $order->getIncrementId()
                . ', the purchase is sent without it: ' . $e::class . ': ' . $e->getMessage()
            );

            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $identity = BrowserIdentity::fromArray($decoded);

        return $identity->isEmpty() ? null : $identity;
    }

    /**
     * Puts the identity on the order object; it is persisted by the order save that
     * follows (`sales_order_place_after` runs before OrderService saves the order).
     */
    public function write(OrderInterface $order, BrowserIdentity $identity): void
    {
        if ($identity->isEmpty() || !method_exists($order, 'setData')) {
            return;
        }

        $order->setData(
            self::COLUMN,
            json_encode($identity->toArray(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }
}
