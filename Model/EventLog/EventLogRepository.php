<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\EventLog;

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Exception\DuplicateEventLogException;
use AxiTrace\Tracking\Model\EventLog\ResourceModel\Collection as EventLogCollection;
use AxiTrace\Tracking\Model\EventLog\ResourceModel\CollectionFactory;
use AxiTrace\Tracking\Model\EventLog\ResourceModel\EventLog as EventLogResource;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Phrase;

/**
 * Default implementation of {@see EventLogRepositoryInterface}.
 *
 * Wraps the resource model with the typed exceptions the rest of the module
 * relies on (DuplicateEventLogException) and centralises retry-queue queries
 * used by the RetryFailedEventsCommand + cron scheduler.
 */
class EventLogRepository implements EventLogRepositoryInterface
{
    public function __construct(
        private readonly EventLogFactory $factory,
        private readonly EventLogResource $resource,
        private readonly CollectionFactory $collectionFactory,
    ) {
    }

    public function save(EventLog $row): EventLog
    {
        try {
            $this->resource->save($row);
            return $row;
        } catch (AlreadyExistsException $e) {
            throw new DuplicateEventLogException((string) $row->getEventIdHash(), $e);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(new Phrase('Failed to save AxiTrace event log row.'), $e);
        }
    }

    public function findById(int $entityId): ?EventLog
    {
        $row = $this->factory->create();
        $this->resource->load($row, $entityId);

        return $row->getId() !== null ? $row : null;
    }

    public function findByEventIdHash(string $eventIdHash): ?EventLog
    {
        $row = $this->factory->create();
        $this->resource->load($row, $eventIdHash, 'event_id_hash');

        return $row->getId() !== null ? $row : null;
    }

    public function findLastSuccess(): ?EventLog
    {
        /** @var EventLogCollection $collection */
        $collection = $this->collectionFactory->create();
        $collection
            ->addFieldToFilter('status', EventLog::STATUS_SENT)
            ->setOrder('sent_at', 'DESC')
            ->setPageSize(1);

        $first = $collection->getFirstItem();
        return $first->getId() !== null ? $first : null;
    }

    /**
     * @return EventLog[]
     */
    public function findRetryable(int $limit, int $maxAttempts = 5): array
    {
        /** @var EventLogCollection $collection */
        $collection = $this->collectionFactory->create();
        $collection
            ->addFieldToFilter('status', EventLog::STATUS_FAILED)
            ->addFieldToFilter('attempts', ['lt' => $maxAttempts])
            ->setOrder('updated_at', 'ASC')
            ->setPageSize($limit);

        return array_values($collection->getItems());
    }
}
