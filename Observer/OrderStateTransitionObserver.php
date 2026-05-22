<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Observer;

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Exception\DuplicateEventLogException;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\EventId\UuidV5Generator;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use AxiTrace\Tracking\Model\EventLog\EventLogFactory;
use AxiTrace\Tracking\Model\Queue\OrderEventPublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Observes sales_order_save_after and publishes an event to the AxiTrace queue
 * exactly once per order lifecycle, on the transition into STATE_PROCESSING.
 *
 * Defence in depth:
 *   1. State transition gate — only fires when prev != processing && current == processing.
 *   2. Idempotency table — INSERT axitrace_event_log row BEFORE queue publish; UNIQUE
 *      constraint blocks double-fire (async payment auto-invoice flows fire save_after
 *      multiple times in a single request).
 *   3. Try/catch wrap — the observer MUST NOT bubble; any throw would roll back the
 *      Magento sales order save transaction.
 */
class OrderStateTransitionObserver implements ObserverInterface
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly EventLogFactory $eventLogFactory,
        private readonly EventLogRepositoryInterface $eventLogRepo,
        private readonly OrderEventPublisher $publisher,
        private readonly UuidV5Generator $uuidGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $this->dispatch($observer);
        } catch (\Throwable $e) {
            // Critical so the message reaches operators via Magento's logger pipeline.
            // Never rethrow — observer exceptions roll back order persistence.
            $this->logger->critical(
                'AxiTrace order observer failed unexpectedly: '
                . $e::class . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    private function dispatch(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        $previousState = (string) ($order->getOrigData('state') ?? '');
        $currentState  = (string) $order->getState();

        // Only forward on transition into "processing".
        if ($currentState !== Order::STATE_PROCESSING) {
            return;
        }

        // Already published before — don't double-fire on subsequent saves.
        if ($previousState === Order::STATE_PROCESSING) {
            return;
        }

        $incrementId = (string) $order->getIncrementId();
        if ($incrementId === '') {
            $this->logger->critical('AxiTrace observer: order missing increment_id, skipping.');
            return;
        }

        $eventIdHash = $this->uuidGenerator->forOrder($incrementId);

        $row = $this->eventLogFactory->create();
        $row
            ->setOrderId((int) $order->getEntityId())
            ->setIncrementId($incrementId)
            ->setStateAtSend($currentState)
            ->setEventIdHash($eventIdHash)
            ->setStatus(EventLog::STATUS_PENDING)
            ->setAttempts(0);

        try {
            $this->eventLogRepo->save($row);
        } catch (DuplicateEventLogException $duplicate) {
            // Already processed (UNIQUE constraint hit) — perfect idempotency outcome.
            $this->logger->info(
                'AxiTrace observer: duplicate event_id_hash skipped for order ' . $incrementId,
                ['event_id_hash' => $eventIdHash]
            );
            return;
        }

        // Publish to the queue. Failure to publish leaves the row in status=pending
        // so the retry cron will surface it.
        try {
            $this->publisher->publishOrder($order, $eventIdHash);
        } catch (\Throwable $e) {
            $this->logger->critical(
                'AxiTrace observer: queue publish failed for order ' . $incrementId
                . ' — row remains pending. Error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
