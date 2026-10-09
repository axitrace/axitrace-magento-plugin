<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Queue;

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Exception\IngestionUnreachableException;
use AxiTrace\Tracking\Exception\SecretKeyRejectedException;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use AxiTrace\Tracking\Model\HttpClient\IngestionApiClient;
use AxiTrace\Tracking\Model\Identity\BrowserIdentity;
use AxiTrace\Tracking\Model\Identity\OrderBrowserIdentityStore;
use AxiTrace\Tracking\Model\Normalizer\OrderEventNormalizer;
use Magento\Sales\Api\Data\OrderInterface;
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
 * Secret key: when the merchant configured the optional AxiTrace secret key, the
 * payload carries product unit costs and the request is authenticated with it. If
 * ingestion rejects the key (401), the purchase is sent again without the key and
 * without costs in the same run: a wrong key must cost the merchant profit data,
 * never the purchase itself. The rejection is logged critical by the client.
 * A key AxiTrace does not recognise at all is answered 2xx with the costs dropped;
 * both cases leave a note on the event log row, which the admin status indicator
 * (Stores > Configuration > AxiTrace) shows to the merchant.
 *
 * Catches \Throwable - Magento's MysqlMq has a silent-drop bug; if we rethrow,
 * the message vanishes. Instead we update the log row, log critically, and
 * return normally so the retry cron handles re-publish.
 */
class OrderEventConsumer
{
    /** Event log note on a purchase sent with a key AxiTrace did not recognise. */
    public const NOTE_KEY_UNVERIFIED = 'Sent without product costs: AxiTrace did not recognise the configured secret key.';

    /** Event log note on a purchase resent without costs after a 401. */
    public const NOTE_KEY_REJECTED = 'Sent without product costs: AxiTrace rejected the configured secret key (it belongs to another workspace).';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly EventLogRepositoryInterface $eventLogRepo,
        private readonly OrderEventNormalizer $normalizer,
        private readonly IngestionApiClient $client,
        private readonly ModuleConfig $config,
        private readonly OrderBrowserIdentityStore $identityStore,
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

            $browser = $this->resolveBrowserIdentity($order, $payload);

            $consent = isset($payload['consent']) ? (string) $payload['consent'] : null;

            $storeId = (int) $order->getStoreId();
            $secretKey = $this->config->getSecretKey($storeId);

            $eventData = $this->normalizer->normalize(
                $order,
                $eventIdHash,
                $workspaceKey,
                $browser,
                $consent,
                $secretKey !== ''
            );
            $payloadJson = (string) json_encode($eventData, JSON_THROW_ON_ERROR);

            $note = null;
            try {
                if (!$this->client->sendOrderEvent($payloadJson, $secretKey, $storeId)) {
                    $note = self::NOTE_KEY_UNVERIFIED;
                }
            } catch (SecretKeyRejectedException $rejected) {
                $eventData = $this->normalizer->normalize(
                    $order,
                    $eventIdHash,
                    $workspaceKey,
                    $browser,
                    $consent,
                    false
                );
                $payloadJson = (string) json_encode($eventData, JSON_THROW_ON_ERROR);

                $this->client->sendOrderEvent($payloadJson, '', $storeId);
                $note = self::NOTE_KEY_REJECTED;
                $this->logger->warning(
                    'AxiTrace consumer: secret key rejected, purchase ' . $eventIdHash
                    . ' sent without product costs.'
                );
            }

            $this->markSent($row, strlen($payloadJson), $note);
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
     * The shopper's browser identity for this purchase, from three sources, each
     * overriding the one before it:
     *   1. `fbp` / `fbc` at the top of a message published by module 0.1.3 - 0.3.0
     *      and still queued when 0.4.0 was installed;
     *   2. the identity stored on the order at placement time;
     *   3. the `browser` object of the message (the observer's resolved identity).
     *
     * @param array<string, mixed> $payload
     */
    private function resolveBrowserIdentity(OrderInterface $order, array $payload): BrowserIdentity
    {
        $legacy = BrowserIdentity::fromArray([
            'fbp' => $payload['fbp'] ?? null,
            'fbc' => $payload['fbc'] ?? null,
        ]);

        $stored = $this->identityStore->read($order) ?? BrowserIdentity::empty();

        $fromMessage = isset($payload['browser']) && is_array($payload['browser'])
            ? BrowserIdentity::fromArray($payload['browser'])
            : BrowserIdentity::empty();

        return $legacy->mergedWith($stored)->mergedWith($fromMessage);
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

    private function markSent(?EventLog $row, int $payloadBytes, ?string $note = null): void
    {
        if ($row === null) {
            return;
        }
        $row
            ->setStatus(EventLog::STATUS_SENT)
            ->setAttempts(((int) $row->getAttempts()) + 1)
            ->setPayloadSizeBytes($payloadBytes)
            ->setSentAt(gmdate('Y-m-d H:i:s'))
            ->setLastError($note);
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
