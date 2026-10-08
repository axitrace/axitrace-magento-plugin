<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\HttpClient;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Exception\IngestionUnreachableException;
use AxiTrace\Tracking\Exception\SecretKeyRejectedException;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\HttpClient\IngestionApiClient;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The secret key travels as `Authorization: Basic base64(<key>:)`, only when set,
 * and a 401 to a keyed request is a distinct, catchable failure.
 */
class IngestionApiClientTest extends TestCase
{
    /** @var array<string, string> */
    private array $headers = [];

    private string $postedUrl = '';

    public function testPurchaseWithoutSecretKeyHasNoAuthorizationHeader(): void
    {
        $this->client(200)->sendOrderEvent('{}');

        self::assertSame('https://stat.example.test/magento/pixel', $this->postedUrl);
        self::assertArrayNotHasKey('Authorization', $this->headers);
    }

    public function testPurchaseWithSecretKeyCarriesBasicAuth(): void
    {
        $this->client(200)->sendOrderEvent('{}', 'sk_test_secret');

        self::assertSame('Basic ' . base64_encode('sk_test_secret:'), $this->headers['Authorization']);
    }

    public function testRefundGoesToTheRefundEndpointWithBasicAuth(): void
    {
        $this->client(202)->sendRefund('{}', 'sk_test_secret');

        self::assertSame('https://stat.example.test/v1/refund', $this->postedUrl);
        self::assertSame('Basic ' . base64_encode('sk_test_secret:'), $this->headers['Authorization']);
    }

    public function testRefundWithoutSecretKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client(200)->sendRefund('{}', '');
    }

    public function testUnauthorizedKeyedRequestThrowsSecretKeyRejected(): void
    {
        $this->expectException(SecretKeyRejectedException::class);

        $this->client(401)->sendOrderEvent('{}', 'sk_test_wrong');
    }

    public function testUnauthorizedRequestWithoutKeyIsAnOrdinaryFailure(): void
    {
        try {
            $this->client(401)->sendOrderEvent('{}');
            self::fail('Expected an exception.');
        } catch (IngestionUnreachableException $e) {
            self::assertNotInstanceOf(SecretKeyRejectedException::class, $e);
        }
    }

    public function testSecretKeyIsNeverSentOverPlainHttp(): void
    {
        $this->client(200, 'http://stat.example.test')->sendOrderEvent('{}', 'sk_test_secret');

        self::assertSame('http://stat.example.test/magento/pixel', $this->postedUrl);
        self::assertArrayNotHasKey('Authorization', $this->headers);
    }

    private function client(int $status, string $baseUrl = 'https://stat.example.test'): IngestionApiClient
    {
        $this->headers = [];
        $this->postedUrl = '';

        $curl = $this->createMock(Curl::class);
        $curl->method('addHeader')->willReturnCallback(function ($name, $value): void {
            $this->headers[(string) $name] = (string) $value;
        });
        $curl->method('post')->willReturnCallback(function ($url): void {
            $this->postedUrl = (string) $url;
        });
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn('');

        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getApiBaseUrl')->willReturn($baseUrl);

        return new IngestionApiClient($factory, $config, $this->createMock(LoggerInterface::class));
    }
}
