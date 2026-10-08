<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Exception;

/**
 * Raised when ingestion-api answers 401 to a request that carried the configured
 * AxiTrace secret key: the key is wrong, revoked or belongs to another workspace.
 *
 * Extends IngestionUnreachableException so any caller that does not handle it
 * explicitly still treats it as a failed send.
 */
class SecretKeyRejectedException extends IngestionUnreachableException
{
}
