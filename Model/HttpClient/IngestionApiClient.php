<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\HttpClient;

use AxiTrace\Tracking\Exception\IngestionUnreachableException;
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
 */
class IngestionApiClient
{
    private const ENDPOINT_PATH = '/magento/pixel';

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
     * @throws IngestionUnreachableException
     */
    public function sendOrderEvent(string $payloadJson): void
    {
        $url = $this->config->getApiBaseUrl() . self::ENDPOINT_PATH;
        $curl = $this->curlFactory->create();

        // Explicit timeouts — Magento ships NO defaults for these; without them
        // a hung ingestion endpoint would block the consumer indefinitely.
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_FAILONERROR, false);

        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');

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
            $this->logger->critical(
                'AxiTrace ingestion-api non-2xx response: status=' . $statusCode
                . ' body=' . $excerpt
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
