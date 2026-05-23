<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\EventLog;

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Model\Queue\OrderEventPublisher;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron entry point for `axitrace_retry_failed` (every 15 minutes).
 *
 * Selects rows where status=failed AND attempts<5 and re-publishes them to the
 * MessageQueue. Re-publishing is preferred over directly POSTing to ingestion-api
 * from the cron because it preserves the consumer's async + at-most-once
 * semantics, including its handling of new failures.
 */
class RetryFailedScheduler
{
    private const RETRY_BATCH_SIZE = 100;
    private const MAX_ATTEMPTS     = 5;

    public function __construct(
        private readonly EventLogRepositoryInterface $eventLogRepo,
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly OrderEventPublisher $publisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $rows = $this->eventLogRepo->findRetryable(self::RETRY_BATCH_SIZE, self::MAX_ATTEMPTS);
        if ($rows === []) {
            return;
        }

        $republished = 0;
        foreach ($rows as $row) {
            try {
                $order = $this->orderRepo->get((int) $row->getOrderId());
                $this->publisher->publishOrder($order, (string) $row->getEventIdHash());
                $row->setStatus(\AxiTrace\Tracking\Model\EventLog\EventLog::STATUS_PENDING);
                $this->eventLogRepo->save($row);
                $republished++;
            } catch (\Throwable $e) {
                // Per-row failures don't stop the batch.
                $this->logger->critical(
                    'AxiTrace retry: failed to re-publish event_id_hash ' . (string) $row->getEventIdHash(),
                    ['exception' => $e]
                );
            }
        }

        $this->logger->info(
            'AxiTrace retry: re-published ' . $republished . ' of ' . count($rows) . ' failed events.'
        );
    }
}
