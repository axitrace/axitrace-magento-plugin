<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Csp;

require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\Csp\TrackingDomainPolicyCollector;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Magento 2.4.7 enforces CSP on checkout: the SDK host and the API host must be
 * allowed, whatever the merchant configured, or the SDK is blocked there.
 */
class TrackingDomainPolicyCollectorTest extends TestCase
{
    public function testAllowsTheConfiguredTrackingDomainAndApiHost(): void
    {
        $policies = $this->collector(true, 'stat.shop.example', 'https://api.shop.example/')->collect(['existing']);

        self::assertSame('existing', $policies[0]);
        self::assertSame(['script-src' => ['stat.shop.example'], 'connect-src' => ['stat.shop.example', 'api.shop.example']], $this->hosts($policies));
    }

    public function testFallsBackToTheDefaultTrackingDomain(): void
    {
        $policies = $this->collector(true, '', 'https://stat.axitrace.com')->collect();

        self::assertSame(['script-src' => ['stat.axitrace.com'], 'connect-src' => ['stat.axitrace.com']], $this->hosts($policies));
    }

    public function testAddsNothingWhileTheModuleIsDisabled(): void
    {
        self::assertSame(['existing'], $this->collector(false, 'stat.shop.example', 'https://stat.axitrace.com')->collect(['existing']));
    }

    public function testAddsNothingOutsideTheStorefront(): void
    {
        self::assertSame(['existing'], $this->collector(true, 'stat.shop.example', 'https://stat.axitrace.com', 'adminhtml')->collect(['existing']));
    }

    public function testIgnoresAMalformedDomain(): void
    {
        $policies = $this->collector(true, 'bad host"; script-src *', 'https://stat.axitrace.com')->collect();

        self::assertSame(['script-src' => ['stat.axitrace.com'], 'connect-src' => ['stat.axitrace.com']], $this->hosts($policies));
    }

    private function collector(bool $enabled, string $trackingDomain, string $apiBaseUrl, string $area = 'frontend'): TrackingDomainPolicyCollector
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getTrackingDomain')->willReturn($trackingDomain);
        $config->method('getApiBaseUrl')->willReturn($apiBaseUrl);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn($area);

        return new TrackingDomainPolicyCollector($config, $storeManager, $state);
    }

    /**
     * @param array<int, mixed> $policies
     * @return array<string, list<string>>
     */
    private function hosts(array $policies): array
    {
        $hosts = [];
        foreach ($policies as $policy) {
            if ($policy instanceof FetchPolicy) {
                self::assertFalse($policy->isNoneAllowed());
                $hosts[$policy->getId()] = $policy->getHostSources();
            }
        }

        return $hosts;
    }
}
