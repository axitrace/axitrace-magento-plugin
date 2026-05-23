<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\EventLog\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for `axitrace_event_log`.
 *
 * The `event_id_hash` column has a UNIQUE constraint at the DB level — duplicate
 * inserts (e.g. from an observer double-fire) raise an exception that callers
 * MUST catch and treat as "event already enqueued" (idempotency win).
 */
class EventLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('axitrace_event_log', 'entity_id');
    }
}
