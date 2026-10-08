<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Observer;

use AxiTrace\Tracking\Model\Refund\RefundSender;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Psr\Log\LoggerInterface;

/**
 * Observes sales_order_creditmemo_save_after and reports the refund to AxiTrace,
 * where it reduces the order's profit and POAS (revenue and ROAS stay unchanged).
 *
 * A canceled credit memo refunds nothing and is not sent. A credit memo saved more
 * than once (a comment added later) is sent again with the same refundId; AxiTrace
 * deduplicates on it, and this observer also skips a repeat within one request.
 *
 * MUST NOT throw: an observer exception would roll back the merchant's refund.
 */
class CreditmemoRefundObserver implements ObserverInterface
{
    /** @var array<string, true> */
    private array $sentInThisRequest = [];

    public function __construct(
        private readonly RefundSender $refundSender,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $creditmemo = $observer->getEvent()->getData('creditmemo');
            if (!$creditmemo instanceof CreditmemoInterface) {
                return;
            }

            if ((int) $creditmemo->getState() === Creditmemo::STATE_CANCELED) {
                return;
            }

            $refundId = (string) $creditmemo->getEntityId();
            if ($refundId === '' || isset($this->sentInThisRequest[$refundId])) {
                return;
            }

            $order = $creditmemo instanceof Creditmemo ? $creditmemo->getOrder() : null;
            if (!$order instanceof OrderInterface) {
                $this->logger->warning('AxiTrace refund: credit memo ' . $refundId . ' has no order, not sent.');
                return;
            }

            $this->sentInThisRequest[$refundId] = true;
            $this->refundSender->sendCreditmemo($creditmemo, $order);
        } catch (\Throwable $e) {
            $this->logger->critical(
                'AxiTrace credit memo observer failed unexpectedly: ' . $e::class . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
