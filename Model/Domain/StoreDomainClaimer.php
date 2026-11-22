<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Domain;

use AxiTrace\Tracking\Model\Normalizer\OrderEventNormalizer;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Asks AxiTrace to verify the storefront hosts, so the browser events of every
 * store view are accepted without the merchant adding the domain by hand.
 *
 * AxiTrace accepts browser events (page views, add-to-cart, checkout) only from
 * verified domains of the workspace. Without a verified domain they are dropped
 * while server-side purchases still arrive, so nothing looks broken.
 *
 * Flow: one POST https://axitrace.com/api/public/store-domains/claim per public key
 * with the hosts of its store views (plus the secret key as HTTP Basic when set).
 * AxiTrace then fetches /axitrace/domain/proof on each host (Controller\Domain\Proof).
 * The claim URL is fixed: it is never the ingestion API base URL, whose host
 * (stat.axitrace.com) does not serve /api. The only exception is a developer
 * override for a local AxiTrace (see claimUrl()), honoured only while Magento
 * runs in developer mode; production and default mode always use axitrace.com.
 *
 * Runs on save of the AxiTrace configuration section, once a day from cron, on the
 * first cron run after install or upgrade when nothing is stored yet, and from the
 * "Verify store domain" button. Never throws: every failure is stored for the
 * status shown in the configuration and logged.
 */
class StoreDomainClaimer
{
    public const CLAIM_URL = 'https://axitrace.com/api/public/store-domains/claim';
    public const CLAIM_PATH = '/api/public/store-domains/claim';

    /**
     * Environment variable with the base URL of a local AxiTrace panel
     * (e.g. http://velitrack-admin:8000), read only in developer mode.
     */
    public const ENV_DEV_ADMIN_BASE_URL = 'AXITRACE_DEV_ADMIN_BASE_URL';

    private const MAX_HOSTS_PER_CLAIM     = 10;
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const REQUEST_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly StoreHosts $storeHosts,
        private readonly StoreDomainStatusStore $statusStore,
        private readonly CurlFactory $curlFactory,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly State $appState,
    ) {
    }

    /**
     * The claim endpoint: always https://axitrace.com, except in developer mode
     * when AXITRACE_DEV_ADMIN_BASE_URL holds a bare http(s) origin (no path,
     * query or whitespace), which then replaces https://axitrace.com. A store in
     * production or default mode never reads the variable.
     */
    public function claimUrl(): string
    {
        if ($this->appState->getMode() !== State::MODE_DEVELOPER) {
            return self::CLAIM_URL;
        }

        $override = getenv(self::ENV_DEV_ADMIN_BASE_URL);
        if (!is_string($override)) {
            return self::CLAIM_URL;
        }
        $override = rtrim(trim($override), '/');
        if (preg_match('~^https?://[^\s/?#]+$~i', $override) !== 1) {
            return self::CLAIM_URL;
        }

        return $override . self::CLAIM_PATH;
    }

    /**
     * Claims only when no claim outcome was ever stored (install or upgrade).
     */
    public function claimIfNeverClaimed(): void
    {
        if ($this->statusStore->load() === null) {
            $this->claim();
        }
    }

    /**
     * Claims every storefront host now and stores the outcome.
     *
     * @return array{checked_at: int, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}
     */
    public function claim(): array
    {
        $groups = $this->storeHosts->groupsByPublicKey();
        if ($groups === []) {
            return $this->store([], 'no_public_key');
        }

        $previous = $this->statusStore->load();
        $hosts = [];
        $error = null;
        foreach ($groups as $publicKey => $group) {
            foreach (array_chunk($group['hosts'], self::MAX_HOSTS_PER_CLAIM) as $chunk) {
                $outcome = $this->post((string) $publicKey, $group['secret_key'], $chunk);
                if ($outcome['error'] !== null) {
                    $error ??= $outcome['error'];
                    $hosts = array_merge($hosts, self::previousEntries($previous, $chunk));
                    continue;
                }
                $hosts = array_merge($hosts, $outcome['hosts']);
            }
        }

        return $this->store($hosts, $error);
    }

    /**
     * @param list<string> $hosts
     * @return array{hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}
     */
    private function post(string $publicKey, string $secretKey, array $hosts): array
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_FAILONERROR, false);
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('User-Agent', 'AxiTrace-Magento/' . OrderEventNormalizer::PLUGIN_VERSION);
        if ($secretKey !== '') {
            $curl->addHeader('Authorization', 'Basic ' . base64_encode($secretKey . ':'));
        }

        $body = (string) json_encode([
            'public_key' => $publicKey,
            'platform'   => DomainProof::PLATFORM,
            'hosts'      => $hosts,
        ], JSON_UNESCAPED_SLASHES);

        try {
            $curl->post($this->claimUrl(), $body);
        } catch (\Throwable $e) {
            $this->logger->error('AxiTrace store domain claim: AxiTrace could not be reached: ' . $e->getMessage());

            return ['hosts' => [], 'error' => 'unreachable'];
        }

        $status = (int) $curl->getStatus();
        $json = json_decode((string) $curl->getBody(), true);
        if ($status !== 200 || !is_array($json) || empty($json['success']) || !is_array($json['hosts'] ?? null)) {
            $error = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : 'http_' . $status;
            $this->logger->error(sprintf(
                'AxiTrace store domain claim refused: HTTP %d %s (hosts: %s)',
                $status,
                $error,
                implode(', ', $hosts)
            ));

            return ['hosts' => [], 'error' => $error];
        }

        return ['hosts' => StoreDomainStatusStore::hostsOf($json['hosts']), 'error' => null];
    }

    /**
     * The last known entries of these hosts, so a failed claim does not erase them.
     *
     * @param array{checked_at: int, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}|null $previous
     * @param list<string> $hosts
     * @return list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>
     */
    private static function previousEntries(?array $previous, array $hosts): array
    {
        if ($previous === null) {
            return [];
        }

        return array_values(array_filter(
            $previous['hosts'],
            static fn (array $entry): bool => in_array($entry['host'], $hosts, true)
        ));
    }

    /**
     * @param list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}> $hosts
     * @return array{checked_at: int, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}
     */
    private function store(array $hosts, ?string $error): array
    {
        $status = [
            'checked_at' => (int) $this->dateTime->gmtTimestamp(),
            'hosts'      => $hosts,
            'error'      => $error,
        ];
        $this->statusStore->save($status);

        return $status;
    }
}
