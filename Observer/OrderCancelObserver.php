<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Observer;

use AxiTrace\Tracking\Model\Refund\RefundSender;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Observes order_cancel_after and reports the cancellation to AxiTrace as a refund
 * with `isCancellation = true`, so the canceled amount stops counting as profit.
 *
 * MUST NOT throw: an observer exception would roll back the cancellation.
 */
class OrderCancelObserver implements ObserverInterface
{
    public function __construct(
        private readonly RefundSender $refundSender,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof OrderInterface) {
                return;
            }

            $this->refundSender->sendCancellation($order);
        } catch (\Throwable $e) {
            $this->logger->critical(
                'AxiTrace order cancel observer failed unexpectedly: ' . $e::class . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
