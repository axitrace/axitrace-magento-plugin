<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\HttpClient;

use AxiTrace\Tracking\Exception\IngestionUnreachableException;
use AxiTrace\Tracking\Exception\SecretKeyRejectedException;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Thin POST wrapper around Magento's curl client.
 *
 * Hard requirements baked in:
 *   - CURLOPT_TIMEOUT = 5 seconds.
 *   - CURLOPT_CONNECTTIMEOUT = 3 seconds.
 *   - Content-Type: application/json.
 *   - Body is the JSON-encoded GeneratedEvent payload built by the normalizer.
 *
 * Failure modes:
 *   - 2xx response → returns silently (success).
 *   - 4xx/5xx response → throws IngestionUnreachableException with status code
 *     and truncated response body (≤500 bytes, no PII leak risk).
 *   - Network failure (DNS, TCP, TLS, timeout) → throws IngestionUnreachableException.
 *   - 401 to a request that carried the secret key → throws SecretKeyRejectedException
 *     (a subclass), so the caller can resend the purchase without cost data.
 *
 * Secret key: when the merchant configured one, requests carry
 * `Authorization: Basic base64(<secret key>:)`. AxiTrace honours cost fields and
 * accepts refunds only with that header.
 */
class IngestionApiClient
{
    private const ENDPOINT_PATH = '/magento/pixel';
    private const REFUND_PATH   = '/v1/refund';

    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ModuleConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * POST a single event payload (JSON) to ingestion-api.
     *
     * @param string $secretKey Workspace secret key; '' sends no Authorization header.
     *
     * @throws IngestionUnreachableException
     */
    public function sendOrderEvent(string $payloadJson, string $secretKey = '', ?int $storeId = null): void
    {
        $this->post($this->config->getApiBaseUrl($storeId) . self::ENDPOINT_PATH, $payloadJson, $secretKey);
    }

    /**
     * POST a refund or cancellation payload to `/v1/refund`. The endpoint accepts
     * only secret-key authentication, so a call without a key is a programming error.
     *
     * @throws IngestionUnreachableException
     */
    public function sendRefund(string $payloadJson, string $secretKey, ?int $storeId = null): void
    {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('A refund can only be sent with the AxiTrace secret key.');
        }

        $this->post($this->config->getApiBaseUrl($storeId) . self::REFUND_PATH, $payloadJson, $secretKey);
    }

    /**
     * @throws IngestionUnreachableException
     */
    private function post(string $url, string $payloadJson, string $secretKey): void
    {
        $curl = $this->curlFactory->create();

        // Explicit timeouts — Magento ships NO defaults for these; without them
        // a hung ingestion endpoint would block the consumer indefinitely.
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_FAILONERROR, false);

        // Second barrier behind ModuleConfig::getSecretKey(): the key never travels
        // over a URL that is not https.
        if ($secretKey !== '' && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            $this->logger->warning('AxiTrace: secret key not sent over a non-https API base URL.');
            $secretKey = '';
        }

        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');
        if ($secretKey !== '') {
            $curl->addHeader('Authorization', 'Basic ' . base64_encode($secretKey . ':'));
        }

        try {
            $curl->post($url, $payloadJson);
        } catch (\Throwable $e) {
            throw new IngestionUnreachableException(
                'network error: ' . $e->getMessage(),
                $e
            );
        }

        $statusCode = (int) $curl->getStatus();
        $responseBody = (string) $curl->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            $excerpt = substr($responseBody, 0, 500);

            if ($statusCode === 401 && $secretKey !== '') {
                $this->logger->critical(
                    'AxiTrace ingestion-api rejected the configured secret key (HTTP 401) for ' . $url
                    . '. Check Stores > Configuration > AxiTrace > AxiTrace secret key. body=' . $excerpt
                );

                throw new SecretKeyRejectedException('HTTP 401 from ingestion-api: secret key rejected');
            }

            $this->logger->critical(
                'AxiTrace ingestion-api non-2xx response: status=' . $statusCode
                . ' url=' . $url . ' body=' . $excerpt
            );

            throw new IngestionUnreachableException(
                'HTTP ' . $statusCode . ' from ingestion-api'
            );
        }
    }

    /**
     * Probe call used by the admin Test Connection button. Returns the decoded JSON
     * payload on success; throws on failure. The endpoint hit is the workspace
     * tracking-domain resolver (already implemented in event-worker), NOT /magento/pixel.
     *
     * @return array<string, mixed>
     *
     * @throws IngestionUnreachableException
     */
    public function probeTrackingDomain(string $workspacePublicKey): array
    {
        $baseUrl = rtrim($this->config->getApiBaseUrl(), '/');
        $url = $baseUrl . '/api/public/workspace/tracking-domain?key=' . rawurlencode($workspacePublicKey);

        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_FAILONERROR, false);
        $curl->addHeader('Accept', 'application/json');

        try {
            $curl->get($url);
        } catch (\Throwable $e) {
            throw new IngestionUnreachableException('network error: ' . $e->getMessage(), $e);
        }

        $statusCode = (int) $curl->getStatus();
        $body = (string) $curl->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new IngestionUnreachableException('HTTP ' . $statusCode . ' from tracking-domain endpoint');
        }

        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new IngestionUnreachableException('invalid JSON from tracking-domain endpoint', $e);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
