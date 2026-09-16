<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Observer;

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Exception\DuplicateEventLogException;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\Consent\CookieRestrictionConsentResolver;
use AxiTrace\Tracking\Model\EventId\UuidV5Generator;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use AxiTrace\Tracking\Model\EventLog\EventLogFactory;
use AxiTrace\Tracking\Model\Queue\OrderEventPublisher;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Observes sales_order_save_after and publishes an event to the AxiTrace queue
 * exactly once per order lifecycle, on the transition into STATE_PROCESSING.
 *
 * Defence in depth:
 *   1. State transition gate - only fires when prev != processing && current == processing.
 *   2. Idempotency table - INSERT axitrace_event_log row BEFORE queue publish; UNIQUE
 *      constraint blocks double-fire (async payment auto-invoice flows fire save_after
 *      multiple times in a single request).
 *   3. Try/catch wrap - the observer MUST NOT bubble; any throw would roll back the
 *      Magento sales order save transaction.
 *
 * fbp/fbc capture: this observer is the only request-scoped point in the purchase
 * dispatch flow - OrderEventConsumer::process() runs fully asynchronously (MysqlMq
 * cron consumer) with no HTTP request/cookie access. The customer's own Meta browser
 * pixel cookies (_fbp/_fbc) are therefore read here, validated, and embedded directly
 * in the queue message so OrderEventNormalizer can forward them later. Coverage caveat:
 * for payment methods that confirm asynchronously via a server-to-server webhook (not
 * the customer's own browser), this request has no customer cookies either - this is
 * a best-effort enrichment, never a hard requirement for the purchase event.
 *
 * Consent capture: for the same reason the Cookie Restriction Mode decision is read
 * here and travels with the queue message. CookieRestrictionConsentResolver holds the
 * rules; outside the `frontend` area (admin invoice, payment webhook, cron) the buyer
 * cookies are absent by definition, so the event carries no consent state at all
 * rather than a wrong `denied`.
 */
class OrderStateTransitionObserver implements ObserverInterface
{
    /**
     * Facebook Browser ID cookie format: fb.1.<timestamp>.<random_digits>
     * Mirrors UserIdentityService::isValidFbp() in event-worker.
     */
    private const FBP_PATTERN = '/^fb\.\d+\.\d+\.\d+$/';

    /**
     * Facebook Click ID cookie format: fb.1.<timestamp>.<fbclid>
     * Mirrors UserIdentityService::isValidFbc() in event-worker.
     */
    private const FBC_PATTERN = '/^fb\.\d+\.\d+\.[A-Za-z0-9_-]+$/';

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly EventLogFactory $eventLogFactory,
        private readonly EventLogRepositoryInterface $eventLogRepo,
        private readonly OrderEventPublisher $publisher,
        private readonly UuidV5Generator $uuidGenerator,
        private readonly CookieManagerInterface $cookieManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
        private readonly StoreManagerInterface $storeManager,
        private readonly CookieRestrictionConsentResolver $consentResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $this->dispatch($observer);
        } catch (\Throwable $e) {
            // Critical so the message reaches operators via Magento's logger pipeline.
            // Never rethrow - observer exceptions roll back order persistence.
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

        // Already published before - don't double-fire on subsequent saves.
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
            // Already processed (UNIQUE constraint hit) - perfect idempotency outcome.
            $this->logger->info(
                'AxiTrace observer: duplicate event_id_hash skipped for order ' . $incrementId,
                ['event_id_hash' => $eventIdHash]
            );
            return;
        }

        // Publish to the queue. Failure to publish leaves the row in status=pending
        // so the retry cron will surface it.
        try {
            $this->publisher->publishOrder(
                $order,
                $eventIdHash,
                $this->readValidatedCookie('_fbp', self::FBP_PATTERN),
                $this->readValidatedCookie('_fbc', self::FBC_PATTERN),
                $this->resolveConsent($order),
            );
        } catch (\Throwable $e) {
            $this->logger->critical(
                'AxiTrace observer: queue publish failed for order ' . $incrementId
                . ' - row remains pending. Error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Resolves the visitor's Cookie Restriction Mode decision for this order, or null
     * when this request states nothing about consent (restriction mode off, or a
     * non-frontend area such as an admin invoice, a payment webhook or a cron run).
     *
     * Never lets a consent read break the purchase event: a failure is logged with
     * its class and message and the order is published without a consent state, which
     * is exactly what a store with no consent banner sends today.
     */
    private function resolveConsent(OrderInterface $order): ?string
    {
        try {
            $storeId = (int) $order->getStoreId();

            return $this->consentResolver->resolve(
                $this->scopeConfig->isSetFlag(
                    CookieRestrictionConsentResolver::XML_PATH_COOKIE_RESTRICTION,
                    ScopeInterface::SCOPE_STORE,
                    $storeId
                ),
                $this->cookieManager->getCookie(CookieRestrictionConsentResolver::COOKIE_NAME),
                $this->currentAreaCode(),
                (int) $this->storeManager->getStore($storeId)->getWebsiteId(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AxiTrace observer: consent read failed, publishing without a consent state: '
                . $e::class . ': ' . $e->getMessage(),
                ['exception' => $e]
            );

            return null;
        }
    }

    /**
     * Current Magento area code, or null when it has not been set for this process.
     * A missing area code is treated as "not the frontend", so nothing is stamped.
     */
    private function currentAreaCode(): ?string
    {
        try {
            return $this->appState->getAreaCode();
        } catch (\Throwable $e) {
            $this->logger->debug(
                'AxiTrace observer: area code unavailable, no consent state stamped: '
                . $e::class . ': ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Reads a cookie from the current request and returns it only if it matches the
     * given format. Returns null when absent or malformed - never forwards garbage.
     */
    private function readValidatedCookie(string $name, string $pattern): ?string
    {
        $value = $this->cookieManager->getCookie($name);
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match($pattern, $value) === 1 ? $value : null;
    }
}
