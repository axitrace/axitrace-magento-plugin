<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Observer;

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\Identity\BrowserIdentityCapture;
use AxiTrace\Tracking\Model\Identity\OrderBrowserIdentityStore;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Observes `sales_order_place_after` and stores the shopper's browser identity on
 * the order (AxiTrace visitor/session ids, IP, User-Agent, ad platform browser ids
 * and persisted click ids).
 *
 * Why here: `Order::place()` dispatches this event inside the request that places
 * the order, which on the storefront is the shopper's own browser request (Luma
 * checkout via REST, Hyva and payment return pages via the frontend area), and
 * `OrderService::place()` saves the order right after it, so the value set here is
 * persisted with the order itself. The purchase is sent much later and elsewhere
 * (on the transition to `processing`, then from the queue consumer), where none of
 * the shopper's request data exists any more.
 *
 * BrowserIdentityCapture decides whether the request is the shopper's browser at
 * all; an admin-created order or a server-to-server placement stores nothing.
 *
 * Never throws: an exception from an observer here would abort the checkout. A
 * failure is logged critical with its class and message and the order is placed
 * without the identity, which is exactly what module versions before 0.4.0 did.
 */
class CaptureBrowserIdentityObserver implements ObserverInterface
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly BrowserIdentityCapture $capture,
        private readonly OrderBrowserIdentityStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        try {
            if (!$this->config->isEnabled((int) $order->getStoreId())) {
                return;
            }

            if ($this->store->read($order) !== null) {
                return;
            }

            $identity = $this->capture->capture();
            if ($identity === null || $identity->isEmpty()) {
                return;
            }

            $this->store->write($order, $identity);
        } catch (\Throwable $e) {
            $this->logger->critical(
                'AxiTrace: browser identity capture failed for order ' . (string) $order->getIncrementId()
                . ', the purchase will be sent without it: ' . $e::class . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
