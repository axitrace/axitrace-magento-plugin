<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

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
    private const XML_PATH_SECRET_KEY           = 'axitrace/general/secret_key';
    private const XML_PATH_TRACKING_DOMAIN      = 'axitrace/general/tracking_domain';
    private const XML_PATH_API_BASE_URL         = 'axitrace/advanced/api_base_url';
    private const XML_PATH_LOG_LEVEL            = 'axitrace/advanced/log_level';
    private const XML_PATH_EVENT_PREFIX         = 'axitrace/events/';

    private const DEFAULT_API_BASE_URL = 'https://stat.axitrace.com';
    private const DEFAULT_LOG_LEVEL    = 'error';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
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

    /**
     * Returns the decrypted workspace secret key, or '' if not configured.
     *
     * Optional. When set, purchase events carry product costs and refunds are sent;
     * both are accepted by AxiTrace only with `Authorization: Basic base64(<key>:)`.
     *
     * The key is a credential and never travels in clear text: when the API base
     * URL configured for this store view is not https, '' is returned (and a
     * warning logged), so the purchase goes without the Authorization header and
     * without `unitCost`, and no refund is sent - exactly a store without a key.
     */
    public function getSecretKey(?int $storeId = null): string
    {
        $cipher = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SECRET_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($cipher === '') {
            return '';
        }

        $key = trim((string) $this->encryptor->decrypt($cipher));
        if ($key !== '' && !$this->isHttpsApiBaseUrl($storeId)) {
            $this->logger->warning(
                'AxiTrace: the secret key is not used because the API base URL is not https '
                . '(Stores > Configuration > AxiTrace > Advanced). Purchases are sent without '
                . 'product costs and refunds are not reported until the URL uses https.'
            );

            return '';
        }

        return $key;
    }

    /**
     * True when the API base URL for the store view uses https, the only scheme the
     * secret key may travel over.
     */
    public function isHttpsApiBaseUrl(?int $storeId = null): bool
    {
        return strtolower((string) parse_url($this->getApiBaseUrl($storeId), PHP_URL_SCHEME)) === 'https';
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
