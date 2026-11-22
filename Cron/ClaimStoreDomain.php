<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Cron;

use AxiTrace\Tracking\Model\Domain\StoreDomainClaimer;

/**
 * Cron entry points of the store domain claim (etc/crontab.xml):
 * `execute` once a day, `executeIfNeverClaimed` every 15 minutes, which claims only
 * while no outcome is stored, so a store that installed or upgraded the module but
 * never saves its configuration is still verified on the next cron run.
 */
class ClaimStoreDomain
{
    public function __construct(private readonly StoreDomainClaimer $claimer)
    {
    }

    public function execute(): void
    {
        $this->claimer->claim();
    }

    public function executeIfNeverClaimed(): void
    {
        $this->claimer->claimIfNeverClaimed();
    }
}
