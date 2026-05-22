<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\EventLog;

use Magento\Framework\Model\AbstractModel;

/**
 * AxiTrace event-log entity backing the `axitrace_event_log` table.
 *
 * Tracks every order-state transition that should produce an outbound event.
 * Lifecycle:
 *   - Observer INSERTs the row with status=pending BEFORE publishing to MQ.
 *   - Consumer UPDATEs to status=sent (with sent_at) on 2xx ingestion-api response.
 *   - Consumer UPDATEs to status=failed (last_error populated, attempts++) on failure.
 *   - Retry scheduler picks up rows where status=failed AND attempts<5.
 *
 * @method int|null    getOrderId()
 * @method $this       setOrderId(int $orderId)
 * @method string|null getIncrementId()
 * @method $this       setIncrementId(string $incrementId)
 * @method string|null getStateAtSend()
 * @method $this       setStateAtSend(string $state)
 * @method string|null getEventIdHash()
 * @method $this       setEventIdHash(string $hash)
 * @method int|null    getPayloadSizeBytes()
 * @method $this       setPayloadSizeBytes(int $size)
 * @method string|null getStatus()
 * @method $this       setStatus(string $status)
 * @method int|null    getAttempts()
 * @method $this       setAttempts(int $attempts)
 * @method string|null getLastError()
 * @method $this       setLastError(?string $lastError)
 * @method string|null getSentAt()
 * @method $this       setSentAt(?string $sentAt)
 * @method string|null getCreatedAt()
 * @method string|null getUpdatedAt()
 */
class EventLog extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $_eventPrefix = 'axitrace_event_log';

    protected function _construct(): void
    {
        $this->_init(\AxiTrace\Tracking\Model\EventLog\ResourceModel\EventLog::class);
    }
}
