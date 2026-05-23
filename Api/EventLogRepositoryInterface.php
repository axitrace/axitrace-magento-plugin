<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Api;

use AxiTrace\Tracking\Model\EventLog\EventLog;

/**
 * Read/write access to `axitrace_event_log` rows.
 *
 * Implementations MUST translate UNIQUE constraint violations on event_id_hash
 * into a typed exception so the observer can treat duplicate inserts as a normal
 * "event already enqueued" outcome rather than a hard error.
 */
interface EventLogRepositoryInterface
{
    /**
     * Persists a new EventLog row.
     *
     * @throws \AxiTrace\Tracking\Exception\DuplicateEventLogException When event_id_hash already exists.
     * @throws \Magento\Framework\Exception\CouldNotSaveException      On unexpected persistence failure.
     */
    public function save(EventLog $row): EventLog;

    /**
     * Loads a row by entity_id, or returns null if not found.
     */
    public function findById(int $entityId): ?EventLog;

    /**
     * Loads a row by event_id_hash, or returns null if not found.
     */
    public function findByEventIdHash(string $eventIdHash): ?EventLog;

    /**
     * Returns the most recent successful send (status=sent) for the StatusIndicator UI.
     */
    public function findLastSuccess(): ?EventLog;

    /**
     * Returns up to $limit rows where status=failed AND attempts<$maxAttempts.
     *
     * @return EventLog[]
     */
    public function findRetryable(int $limit, int $maxAttempts = 5): array;
}
