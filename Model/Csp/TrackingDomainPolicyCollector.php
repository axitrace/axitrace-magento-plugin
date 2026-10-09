<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Csp;

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Allows the AxiTrace hosts in the storefront Content Security Policy.
 *
 * Magento 2.4.7 enforces CSP on the checkout pages, and every other page reports
 * violations. The SDK is loaded from the tracking domain (`script-src`) and sends
 * its events to the API base URL and the tracking domain (`connect-src`). Both
 * hosts are store configuration (a merchant may run AxiTrace on their own
 * subdomain), so a static `csp_whitelist.xml` cannot list them: without this
 * collector the browser blocks the SDK on checkout and no begin_checkout or
 * add_payment_info event is ever sent.
 *
 * Nothing is added outside the storefront, or while the module is disabled for the
 * current store.
 */
class TrackingDomainPolicyCollector implements PolicyCollectorInterface
{
    private const DEFAULT_TRACKING_DOMAIN = 'stat.axitrace.com';

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState,
    ) {
    }

    /**
     * @param list<\Magento\Csp\Api\Data\PolicyInterface> $defaultPolicies
     * @return list<\Magento\Csp\Api\Data\PolicyInterface>
     */
    public function collect(array $defaultPolicies = []): array
    {
        if (!$this->isStorefront()) {
            return $defaultPolicies;
        }

        $storeId = $this->storeId();
        if (!$this->config->isEnabled($storeId)) {
            return $defaultPolicies;
        }

        $trackingHost = $this->host($this->config->getTrackingDomain($storeId)) ?? self::DEFAULT_TRACKING_DOMAIN;
        $apiHost = $this->host($this->config->getApiBaseUrl($storeId));

        $connectHosts = array_values(array_unique(array_filter([$trackingHost, $apiHost])));

        $defaultPolicies[] = new FetchPolicy('script-src', false, [$trackingHost]);
        $defaultPolicies[] = new FetchPolicy('connect-src', false, $connectHosts);

        return $defaultPolicies;
    }

    /**
     * The bare host of a configured domain or URL ("stat.example.com",
     * "https://stat.example.com/"), or null when there is none.
     */
    private function host(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $host = parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return null;
        }

        return strtolower($host);
    }

    private function isStorefront(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable) {
            return false;
        }
    }

    private function storeId(): ?int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            return null;
        }
    }
}
