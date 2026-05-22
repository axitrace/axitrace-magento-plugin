<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Console\Command;

use AxiTrace\Tracking\Model\EventLog\RetryFailedScheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento axitrace:retry-failed` — manual retry of failed events.
 *
 * Identical effect to the cron `axitrace_retry_failed` job — useful for
 * one-off debugging or post-incident replay without waiting for the next
 * 15-minute tick.
 */
class RetryFailedEventsCommand extends Command
{
    public function __construct(
        private readonly RetryFailedScheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('axitrace:retry-failed');
        $this->setDescription('Re-publishes failed AxiTrace events (status=failed, attempts<5) to the queue.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>AxiTrace: re-publishing failed events...</info>');
        try {
            $this->scheduler->execute();
            $output->writeln('<info>Done. Inspect axitrace.log for per-event detail.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>AxiTrace retry command failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
