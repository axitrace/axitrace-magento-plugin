<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Raised when the AxiTrace ingestion-api cannot be contacted (DNS, TCP, TLS,
 * or timeout failure) — distinct from a 4xx/5xx response, which is treated as
 * a permanent business-level failure.
 *
 * The consumer catches this exception, marks the row status=failed with
 * last_error populated, and lets the retry cron pick it up.
 */
class IngestionUnreachableException extends LocalizedException
{
    public function __construct(string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(
            new Phrase('AxiTrace ingestion-api unreachable: %1', [$reason]),
            $previous
        );
    }
}
