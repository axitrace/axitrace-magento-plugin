<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Config;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The secret key is a credential: it is handed out only for an https API base URL.
 * Without https the module behaves as if no key were configured (no Authorization
 * header, no `unitCost`, no refunds) and says why in a warning.
 */
class ModuleConfigTest extends TestCase
{
    public function testSecretKeyIsReturnedForTheDefaultHttpsBaseUrl(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertSame('sk_live_secret', $this->config('', $logger)->getSecretKey(1));
    }

    public function testSecretKeyIsReturnedForAnHttpsOverride(): void
    {
        self::assertSame('sk_live_secret', $this->config('https://track.shop.example/')->getSecretKey(1));
    }

    public function testSecretKeyIsWithheldAndAWarningLoggedForAPlainHttpBaseUrl(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::logicalAnd(
            self::stringContains('not https'),
            self::logicalNot(self::stringContains('sk_live_secret')),
        ));

        $config = $this->config('http://track.shop.example', $logger);

        self::assertSame('', $config->getSecretKey(1));
        self::assertFalse($config->isHttpsApiBaseUrl(1));
    }

    public function testNoWarningWhenNoKeyIsConfigured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertSame('', $this->config('http://track.shop.example', $logger, cipher: '')->getSecretKey(1));
    }

    private function config(string $apiBaseUrl, ?LoggerInterface $logger = null, string $cipher = 'cipher'): ModuleConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => match ($path) {
                'axitrace/general/secret_key'   => $cipher,
                'axitrace/advanced/api_base_url' => $apiBaseUrl,
                default                          => null,
            }
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('sk_live_secret');

        return new ModuleConfig($scopeConfig, $encryptor, $logger ?? $this->createMock(LoggerInterface::class));
    }
}
