<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Queue;

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Exception\IngestionUnreachableException;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use AxiTrace\Tracking\Model\HttpClient\IngestionApiClient;
use AxiTrace\Tracking\Model\Normalizer\OrderEventNormalizer;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Consumes axitrace.order.placed messages.
 *
 * Workflow:
 *   1. Decode JSON payload (order_id, increment_id, event_id_hash).
 *   2. Re-fetch the order via OrderRepositoryInterface - never trust serialised state.
 *   3. Normalize via OrderEventNormalizer.
 *   4. POST via IngestionApiClient with explicit 5s/3s timeouts.
 *   5. Update axitrace_event_log row to `sent` or `failed`.
 *
 * Catches \Throwable - Magento's MysqlMq has a silent-drop bug; if we rethrow,
 * the message vanishes. Instead we update the log row, log critically, and
 * return normally so the retry cron handles re-publish.
 */
class OrderEventConsumer
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly EventLogRepositoryInterface $eventLogRepo,
        private readonly OrderEventNormalizer $normalizer,
        private readonly IngestionApiClient $client,
        private readonly ModuleConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(string $message): void
    {
        $payload = $this->decodePayload($message);
        if ($payload === null) {
            return;
        }

        $eventIdHash = (string) ($payload['event_id_hash'] ?? '');
        $row = $eventIdHash !== '' ? $this->eventLogRepo->findByEventIdHash($eventIdHash) : null;

        try {
            $order = $this->orderRepo->get((int) $payload['order_id']);

            if (!$this->config->isEnabled((int) $order->getStoreId())) {
                $this->markSkipped($row, 'Module disabled at consume time.');
                return;
            }

            $workspaceKey = $this->config->getWorkspacePublicKey((int) $order->getStoreId());
            if ($workspaceKey === '') {
                $this->markSkipped($row, 'Workspace public key not configured at consume time.');
                return;
            }

            $fbp = isset($payload['fbp']) ? (string) $payload['fbp'] : null;
            $fbc = isset($payload['fbc']) ? (string) $payload['fbc'] : null;

            $consent = isset($payload['consent']) ? (string) $payload['consent'] : null;

            $eventData = $this->normalizer->normalize(
                $order,
                $eventIdHash,
                $workspaceKey,
                $fbp,
                $fbc,
                $consent
            );
            $payloadJson = (string) json_encode($eventData, JSON_THROW_ON_ERROR);

            $this->client->sendOrderEvent($payloadJson);

            $this->markSent($row, strlen($payloadJson));
        } catch (IngestionUnreachableException $e) {
            $this->markFailed($row, 'ingestion_unreachable: ' . $e->getMessage());
            $this->logger->critical(
                'AxiTrace consumer: ingestion unreachable for hash ' . $eventIdHash,
                ['exception' => $e]
            );
        } catch (\Throwable $e) {
            $this->markFailed($row, $e::class . ': ' . $e->getMessage());
            $this->logger->critical(
                'AxiTrace consumer: unexpected failure for hash ' . $eventIdHash,
                ['exception' => $e]
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $message): ?array
    {
        try {
            $decoded = json_decode($message, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->critical(
                'AxiTrace consumer: message JSON decode failed - message discarded.',
                ['exception' => $e]
            );
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['order_id'], $decoded['event_id_hash'])) {
            $this->logger->critical('AxiTrace consumer: malformed payload - message discarded.');
            return null;
        }

        return $decoded;
    }

    private function markSent(?EventLog $row, int $payloadBytes): void
    {
        if ($row === null) {
            return;
        }
        $row
            ->setStatus(EventLog::STATUS_SENT)
            ->setAttempts(((int) $row->getAttempts()) + 1)
            ->setPayloadSizeBytes($payloadBytes)
            ->setSentAt(gmdate('Y-m-d H:i:s'))
            ->setLastError(null);
        $this->eventLogRepo->save($row);
    }

    private function markFailed(?EventLog $row, string $reason): void
    {
        if ($row === null) {
            return;
        }
        $row
            ->setStatus(EventLog::STATUS_FAILED)
            ->setAttempts(((int) $row->getAttempts()) + 1)
            ->setLastError(substr($reason, 0, 500));
        $this->eventLogRepo->save($row);
    }

    private function markSkipped(?EventLog $row, string $reason): void
    {
        if ($row === null) {
            return;
        }
        $row
            ->setStatus(EventLog::STATUS_SKIPPED)
            ->setLastError(substr($reason, 0, 500));
        $this->eventLogRepo->save($row);
    }
}
