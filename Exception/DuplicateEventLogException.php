<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Exception;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Phrase;

/**
 * Raised when an attempt is made to insert a row into `axitrace_event_log`
 * with an `event_id_hash` that already exists.
 *
 * This is NOT a real error — it means the underlying order+state combination
 * has already been enqueued (e.g. by a prior observer fire). Callers MUST
 * catch this and continue silently.
 */
class DuplicateEventLogException extends AlreadyExistsException
{
    public function __construct(string $eventIdHash, ?\Throwable $previous = null)
    {
        parent::__construct(
            new Phrase('AxiTrace event log row already exists for event_id_hash %1.', [$eventIdHash]),
            $previous
        );
    }
}
