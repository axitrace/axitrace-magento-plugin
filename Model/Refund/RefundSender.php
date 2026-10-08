<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Refund;

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\EventId\UuidV5Generator;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use AxiTrace\Tracking\Model\HttpClient\IngestionApiClient;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends credit memos and order cancellations to AxiTrace `POST /v1/refund`.
 *
 * Sent only when every condition holds, otherwise skipped with a log line:
 *   - the module is enabled for the order's store;
 *   - the optional AxiTrace secret key is configured (the endpoint accepts nothing
 *     else, and without it the merchant has not opted into profit tracking);
 *   - this module recorded a purchase event for the order (a row in
 *     `axitrace_event_log` that was not skipped). A cancelled order that never
 *     reached "processing" was never reported as a purchase, so there is nothing
 *     to reverse.
 *
 * Never throws: it runs inside the credit memo and cancellation save of the merchant's
 * admin, where an exception would roll the refund back. Every failure is logged with
 * its class and message; a failed send is logged critical. AxiTrace deduplicates on
 * `refundId`, so a credit memo saved more than once is recorded once.
 */
class RefundSender
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly RefundPayloadBuilder $payloadBuilder,
        private readonly IngestionApiClient $client,
        private readonly EventLogRepositoryInterface $eventLogRepo,
        private readonly UuidV5Generator $uuidGenerator,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendCreditmemo(CreditmemoInterface $creditmemo, OrderInterface $order): void
    {
        $this->send(
            $order,
            'credit memo ' . (string) $creditmemo->getEntityId(),
            fn (string $now): ?array => $this->payloadBuilder->fromCreditmemo($creditmemo, $order, $now),
        );
    }

    public function sendCancellation(OrderInterface $order): void
    {
        $this->send(
            $order,
            'cancellation',
            fn (string $now): ?array => $this->payloadBuilder->fromCancellation($order, $now),
        );
    }

    /**
     * @param callable(string): (array<string, mixed>|null) $build
     */
    private function send(OrderInterface $order, string $what, callable $build): void
    {
        $incrementId = (string) $order->getIncrementId();

        try {
            $storeId = (int) $order->getStoreId();
            if (!$this->config->isEnabled($storeId)) {
                return;
            }

            $secretKey = $this->config->getSecretKey($storeId);
            if ($secretKey === '') {
                $this->logger->debug(
                    'AxiTrace refund: no secret key configured, ' . $what . ' of order ' . $incrementId . ' not sent.'
                );
                return;
            }

            if (!$this->purchaseWasReported($incrementId)) {
                $this->logger->info(
                    'AxiTrace refund: order ' . $incrementId . ' was never reported as a purchase, '
                    . $what . ' not sent.'
                );
                return;
            }

            $payload = $build((string) $this->dateTime->gmtDate('Y-m-d H:i:s'));
            if ($payload === null) {
                $this->logger->info(
                    'AxiTrace refund: ' . $what . ' of order ' . $incrementId . ' has no amount, not sent.'
                );
                return;
            }

            $this->client->sendRefund((string) json_encode($payload, JSON_THROW_ON_ERROR), $secretKey, $storeId);

            $this->logger->info(
                'AxiTrace refund: ' . $what . ' of order ' . $incrementId . ' sent (refundId '
                . (string) $payload['refundId'] . ').'
            );
        } catch (\Throwable $e) {
            $this->logger->critical(
                'AxiTrace refund: sending ' . $what . ' of order ' . $incrementId . ' failed: '
                . $e::class . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    private function purchaseWasReported(string $incrementId): bool
    {
        if ($incrementId === '') {
            return false;
        }

        $row = $this->eventLogRepo->findByEventIdHash($this->uuidGenerator->forOrder($incrementId));

        return $row !== null && (string) $row->getData('status') !== EventLog::STATUS_SKIPPED;
    }
}
