<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Read-only access to AxiTrace_Tracking system configuration.
 *
 * Centralises every getter so callers never reach into ScopeConfig directly with
 * raw path strings. The workspace public key is stored encrypted at rest and is
 * decrypted here on demand via Magento's EncryptorInterface.
 */
class ModuleConfig
{
    private const XML_PATH_ENABLED              = 'axitrace/general/enabled';
    private const XML_PATH_WORKSPACE_PUBLIC_KEY = 'axitrace/general/workspace_public_key';
    private const XML_PATH_TRACKING_DOMAIN      = 'axitrace/general/tracking_domain';
    private const XML_PATH_API_BASE_URL         = 'axitrace/advanced/api_base_url';
    private const XML_PATH_LOG_LEVEL            = 'axitrace/advanced/log_level';
    private const XML_PATH_EVENT_PREFIX         = 'axitrace/events/';

    private const DEFAULT_API_BASE_URL = 'https://stat.axitrace.com';
    private const DEFAULT_LOG_LEVEL    = 'error';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the decrypted workspace public key, or '' if not configured.
     */
    public function getWorkspacePublicKey(?int $storeId = null): string
    {
        $cipher = (string) $this->scopeConfig->getValue(
            self::XML_PATH_WORKSPACE_PUBLIC_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($cipher === '') {
            return '';
        }

        return (string) $this->encryptor->decrypt($cipher);
    }

    public function getTrackingDomain(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_TRACKING_DOMAIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== '' ? rtrim($value, '/') : self::DEFAULT_API_BASE_URL;
    }

    public function getLogLevel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_LOG_LEVEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== '' ? $value : self::DEFAULT_LOG_LEVEL;
    }

    /**
     * Returns true when the given per-event toggle is enabled.
     *
     * @param string $eventName One of: purchase, add_to_cart, view_content, view_category, page_view
     */
    public function isEventEnabled(string $eventName, ?int $storeId = null): bool
    {
        $path = self::XML_PATH_EVENT_PREFIX . $eventName . '_enabled';

        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
