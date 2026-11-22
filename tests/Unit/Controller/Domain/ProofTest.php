<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Controller\Domain;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Controller\Domain\Proof;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\Domain\DomainProof;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * GET /axitrace/domain/proof answers AxiTrace's store domain proof with exactly the
 * keys the server accepts, signed for the claimed host, and is never cached.
 */
class ProofTest extends TestCase
{
    private const NONCE = 'abcdefghijklmnop_0123-XYZ';
    private const PUBLIC_KEY = 'pk_live_abcdefghijklmnopqrstuvwx';
    private const SECRET_KEY = 'sk_live_secret';

    /** @var array<mixed> */
    private array $data = [];

    private int $code = 0;

    /** @var array<string, string> */
    private array $headers = [];

    public function testAnswersTheProofWithTheStoreViewsKeysSignedForTheRequestHost(): void
    {
        $this->proof(self::NONCE, self::PUBLIC_KEY, self::SECRET_KEY, 'www.shop.com:443')->execute();

        self::assertSame(200, $this->code);
        self::assertSame(
            ['axitrace_domain_proof', 'public_key', 'nonce', 'platform', 'signature'],
            array_keys($this->data)
        );
        self::assertSame(1, $this->data['axitrace_domain_proof']);
        self::assertSame(self::PUBLIC_KEY, $this->data['public_key']);
        self::assertSame(self::NONCE, $this->data['nonce']);
        self::assertSame('magento', $this->data['platform']);
        self::assertSame(
            hash_hmac('sha256', self::NONCE . '|www.shop.com|' . self::PUBLIC_KEY, self::SECRET_KEY),
            $this->data['signature']
        );
        self::assertStringContainsString('no-store', $this->headers['Cache-Control']);
    }

    public function testRequestHostOutsideTheStoreViewIsSignedWithTheSecureBaseUrlHost(): void
    {
        $this->proof(self::NONCE, self::PUBLIC_KEY, self::SECRET_KEY, 'shop.com')->execute();

        self::assertSame(DomainProof::signature(self::SECRET_KEY, self::NONCE, 'www.shop.com', self::PUBLIC_KEY), $this->data['signature']);
    }

    public function testWithoutSecretKeyTheProofCarriesNoSignature(): void
    {
        $this->proof(self::NONCE, self::PUBLIC_KEY, '', 'www.shop.com')->execute();

        self::assertSame(200, $this->code);
        self::assertArrayNotHasKey('signature', $this->data);
    }

    public function testMalformedNonceIsRefused(): void
    {
        $this->proof('short', self::PUBLIC_KEY, self::SECRET_KEY, 'www.shop.com')->execute();

        self::assertSame(400, $this->code);
        self::assertSame(['error' => 'invalid_nonce'], $this->data);
    }

    public function testStoreViewWithoutPublicKeyAnswersNotConfigured(): void
    {
        $this->proof(self::NONCE, '', self::SECRET_KEY, 'www.shop.com')->execute();

        self::assertSame(404, $this->code);
        self::assertSame(['error' => 'not_configured'], $this->data);
    }

    private function proof(string $nonce, string $publicKey, string $secretKey, string $hostHeader): Proof
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getQueryValue')->willReturnCallback(
            static fn ($name = null, $default = null) => $name === 'nonce' ? $nonce : $default
        );
        $request->method('getServerValue')->willReturnCallback(
            static fn ($name = null, $default = null) => $name === 'HTTP_HOST' ? $hostHeader : $default
        );

        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(function ($data) use ($result) {
            $this->data = $data;

            return $result;
        });
        $result->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($result) {
            $this->code = (int) $code;

            return $result;
        });
        $result->method('setHeader')->willReturnCallback(function ($name, $value) use ($result) {
            $this->headers[$name] = (string) $value;

            return $result;
        });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getBaseUrl')->willReturnCallback(
            static fn ($type = UrlInterface::URL_TYPE_LINK, $secure = null) => $secure
                ? 'https://www.shop.com/'
                : 'http://www.shop.com/'
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('getWorkspacePublicKey')->with(3)->willReturn($publicKey);
        $config->method('getDomainProofSecretKey')->with(3)->willReturn($secretKey);

        return new Proof($request, $factory, $storeManager, $config);
    }
}
