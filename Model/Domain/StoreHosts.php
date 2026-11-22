<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Domain;

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The storefront hosts this Magento installation asks AxiTrace to verify.
 *
 * Every active store view with tracking enabled and a workspace public key
 * contributes the hosts of its secure and unsecure base URLs. Store views are
 * grouped per public key, because one claim names exactly one workspace.
 */
class StoreHosts
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleConfig $config,
    ) {
    }

    /**
     * @return array<string, array{secret_key: string, hosts: list<string>}> keyed by public key
     */
    public function groupsByPublicKey(): array
    {
        $groups = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            if (!$store instanceof Store || !$store->getIsActive()) {
                continue;
            }
            $storeId = (int) $store->getId();
            $publicKey = trim($this->config->getWorkspacePublicKey($storeId));
            if ($publicKey === '' || !$this->config->isEnabled($storeId)) {
                continue;
            }

            $group = $groups[$publicKey] ?? ['secret_key' => '', 'hosts' => []];
            if ($group['secret_key'] === '') {
                $group['secret_key'] = $this->config->getDomainProofSecretKey($storeId);
            }
            foreach (self::hostsOf($store) as $host) {
                if (!in_array($host, $group['hosts'], true)) {
                    $group['hosts'][] = $host;
                }
            }
            $groups[$publicKey] = $group;
        }

        return array_filter($groups, static fn (array $group): bool => $group['hosts'] !== []);
    }

    /**
     * The hosts of a store view's secure and unsecure base URLs.
     *
     * @return list<string>
     */
    public static function hostsOf(Store $store): array
    {
        $hosts = [];
        foreach ([true, false] as $secure) {
            $host = self::hostOf((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $secure));
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * The bare lower-case host of a URL (no scheme, port or trailing dot), '' when none.
     */
    public static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? rtrim(strtolower($host), '.') : '';
    }

    /**
     * A Host header value without its port, lower-case.
     */
    public static function normalizeHostHeader(string $hostHeader): string
    {
        $hostHeader = trim($hostHeader);
        if ($hostHeader === '') {
            return '';
        }

        return self::hostOf('https://' . $hostHeader);
    }
}
