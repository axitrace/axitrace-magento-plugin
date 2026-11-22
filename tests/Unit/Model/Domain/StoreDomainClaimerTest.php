<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Domain;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Domain\StoreDomainClaimer;
use AxiTrace\Tracking\Model\Domain\StoreDomainStatusStore;
use AxiTrace\Tracking\Model\Domain\StoreHosts;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The claim goes to the fixed axitrace.com URL, one request per public key with
 * that key's hosts, and every outcome (also a failure) is stored for the status
 * shown in the configuration; a failed claim keeps the hosts' last known state.
 */
class StoreDomainClaimerTest extends TestCase
{
    /** @var list<array{url: string, body: array<mixed>, headers: array<string, string>}> */
    private array $requests = [];

    /** @var array<mixed>|null */
    private ?array $saved = null;

    private string $mode = State::MODE_PRODUCTION;

    protected function tearDown(): void
    {
        putenv(StoreDomainClaimer::ENV_DEV_ADMIN_BASE_URL);
    }

    public function testDeveloperModeSendsTheClaimToTheLocalPanelOfTheOverride(): void
    {
        $this->mode = State::MODE_DEVELOPER;
        putenv(StoreDomainClaimer::ENV_DEV_ADMIN_BASE_URL . '=http://velitrack-admin:8000/');

        $this->claimer(
            ['pk_live_aaaaaaaaaaaaaaaaaaaaaaaa' => ['secret_key' => '', 'hosts' => ['mage-e2e.localhost']]],
            [[200, ['success' => true, 'hosts' => []]]]
        )->claim();

        self::assertSame('http://velitrack-admin:8000/api/public/store-domains/claim', $this->requests[0]['url']);
    }

    /**
     * @dataProvider productionUrlCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('productionUrlCases')]
    public function testTheOverrideIsIgnoredOutsideDeveloperModeOrWhenItIsNotABareOrigin(string $mode, ?string $override): void
    {
        $this->mode = $mode;
        if ($override !== null) {
            putenv(StoreDomainClaimer::ENV_DEV_ADMIN_BASE_URL . '=' . $override);
        }

        $this->claimer(
            ['pk_live_aaaaaaaaaaaaaaaaaaaaaaaa' => ['secret_key' => '', 'hosts' => ['shop.com']]],
            [[200, ['success' => true, 'hosts' => []]]]
        )->claim();

        self::assertSame('https://axitrace.com/api/public/store-domains/claim', $this->requests[0]['url']);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function productionUrlCases(): array
    {
        return [
            'production mode with the variable set' => [State::MODE_PRODUCTION, 'http://velitrack-admin:8000'],
            'default mode with the variable set'    => [State::MODE_DEFAULT, 'http://velitrack-admin:8000'],
            'developer mode without the variable'   => [State::MODE_DEVELOPER, null],
            'developer mode, value with a path'     => [State::MODE_DEVELOPER, 'http://evil.example/steal?x='],
            'developer mode, not http(s)'           => [State::MODE_DEVELOPER, 'file:///etc/passwd'],
            'developer mode, empty value'           => [State::MODE_DEVELOPER, ''],
        ];
    }

    public function testClaimsEachPublicKeyWithItsHostsAtAxiTraceAndStoresTheAnswer(): void
    {
        $claimer = $this->claimer(
            [
                'pk_live_aaaaaaaaaaaaaaaaaaaaaaaa' => ['secret_key' => 'sk_live_a', 'hosts' => ['shop.com', 'www.shop.com']],
                'pk_live_bbbbbbbbbbbbbbbbbbbbbbbb' => ['secret_key' => '', 'hosts' => ['shop.de']],
            ],
            [
                [200, ['success' => true, 'hosts' => [
                    ['host' => 'shop.com', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false],
                    ['host' => 'www.shop.com', 'status' => 'pending', 'reason' => null, 'browser_events_rejected' => false],
                ]]],
                [200, ['success' => true, 'hosts' => [
                    ['host' => 'shop.de', 'status' => 'failed', 'reason' => 'proof_not_found', 'browser_events_rejected' => true],
                ]]],
            ]
        );

        $status = $claimer->claim();

        self::assertCount(2, $this->requests);
        self::assertSame('https://axitrace.com/api/public/store-domains/claim', $this->requests[0]['url']);
        self::assertSame(
            ['public_key' => 'pk_live_aaaaaaaaaaaaaaaaaaaaaaaa', 'platform' => 'magento', 'hosts' => ['shop.com', 'www.shop.com']],
            $this->requests[0]['body']
        );
        self::assertSame('Basic ' . base64_encode('sk_live_a:'), $this->requests[0]['headers']['Authorization']);
        self::assertSame(['shop.de'], $this->requests[1]['body']['hosts']);
        self::assertArrayNotHasKey('Authorization', $this->requests[1]['headers']);

        self::assertNull($status['error']);
        self::assertSame(['shop.com', 'www.shop.com', 'shop.de'], array_column($status['hosts'], 'host'));
        self::assertSame('proof_not_found', $status['hosts'][2]['reason']);
        self::assertTrue($status['hosts'][2]['browser_events_rejected']);
        self::assertSame($status, $this->saved);
    }

    public function testRefusedClaimStoresTheErrorAndKeepsTheLastKnownHostState(): void
    {
        $previous = [
            'checked_at' => 1,
            'hosts'      => [
                ['host' => 'shop.com', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false],
                ['host' => 'gone.com', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false],
            ],
            'error'      => null,
        ];
        $claimer = $this->claimer(
            ['pk_live_aaaaaaaaaaaaaaaaaaaaaaaa' => ['secret_key' => 'sk_live_wrong', 'hosts' => ['shop.com']]],
            [[401, ['success' => false, 'error' => 'invalid_secret_key']]],
            $previous
        );

        $status = $claimer->claim();

        self::assertSame('invalid_secret_key', $status['error']);
        self::assertSame(['shop.com'], array_column($status['hosts'], 'host'));
        self::assertSame($status, $this->saved);
    }

    public function testUnreachableAxiTraceIsStoredNotThrown(): void
    {
        $claimer = $this->claimer(
            ['pk_live_aaaaaaaaaaaaaaaaaaaaaaaa' => ['secret_key' => '', 'hosts' => ['shop.com']]],
            [new \RuntimeException('Could not resolve host')]
        );

        $status = $claimer->claim();

        self::assertSame('unreachable', $status['error']);
        self::assertSame([], $status['hosts']);
    }

    public function testWithoutAnyConfiguredStoreViewNothingIsSentAndNoPublicKeyIsStored(): void
    {
        $status = $this->claimer([], [])->claim();

        self::assertSame([], $this->requests);
        self::assertSame('no_public_key', $status['error']);
        self::assertNotNull($this->saved);
    }

    public function testMoreThanTenHostsAreSplitIntoSeveralClaims(): void
    {
        $hosts = array_map(static fn (int $i): string => 'shop' . $i . '.com', range(1, 12));
        $ok = [200, ['success' => true, 'hosts' => []]];
        $this->claimer(['pk_live_aaaaaaaaaaaaaaaaaaaaaaaa' => ['secret_key' => '', 'hosts' => $hosts]], [$ok, $ok])->claim();

        self::assertCount(2, $this->requests);
        self::assertCount(10, $this->requests[0]['body']['hosts']);
        self::assertSame(['shop11.com', 'shop12.com'], $this->requests[1]['body']['hosts']);
    }

    public function testFirstRunClaimsOnlyWhileNothingIsStored(): void
    {
        $stored = ['checked_at' => 1, 'hosts' => [], 'error' => null];
        $this->claimer(['pk_live_aaaaaaaaaaaaaaaaaaaaaaaa' => ['secret_key' => '', 'hosts' => ['shop.com']]], [], $stored)
            ->claimIfNeverClaimed();
        self::assertSame([], $this->requests);

        $this->claimer(
            ['pk_live_aaaaaaaaaaaaaaaaaaaaaaaa' => ['secret_key' => '', 'hosts' => ['shop.com']]],
            [[200, ['success' => true, 'hosts' => []]]]
        )->claimIfNeverClaimed();
        self::assertCount(1, $this->requests);
    }

    /**
     * @param array<string, array{secret_key: string, hosts: list<string>}> $groups
     * @param list<array{0: int, 1: array<mixed>}|\Throwable> $responses
     * @param array<mixed>|null $previous
     */
    private function claimer(array $groups, array $responses, ?array $previous = null): StoreDomainClaimer
    {
        $storeHosts = $this->createMock(StoreHosts::class);
        $storeHosts->method('groupsByPublicKey')->willReturn($groups);

        $statusStore = $this->createMock(StoreDomainStatusStore::class);
        $statusStore->method('load')->willReturn($previous);
        $statusStore->method('save')->willReturnCallback(function (array $status): void {
            $this->saved = $status;
        });

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturnCallback(function () use (&$responses) {
            $response = array_shift($responses);
            $headers = [];
            $curl = $this->createMock(Curl::class);
            $curl->method('addHeader')->willReturnCallback(static function ($name, $value) use (&$headers): void {
                $headers[$name] = $value;
            });
            $curl->method('post')->willReturnCallback(function ($url, $body) use ($response, &$headers): void {
                $this->requests[] = ['url' => $url, 'body' => json_decode($body, true), 'headers' => $headers];
                if ($response instanceof \Throwable) {
                    throw $response;
                }
            });
            $curl->method('getStatus')->willReturn(is_array($response) ? $response[0] : 0);
            $curl->method('getBody')->willReturn(is_array($response) ? (string) json_encode($response[1]) : '');

            return $curl;
        });

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_763_800_000);

        $appState = $this->createMock(State::class);
        $appState->method('getMode')->willReturn($this->mode);

        return new StoreDomainClaimer(
            $storeHosts,
            $statusStore,
            $curlFactory,
            $dateTime,
            $this->createMock(LoggerInterface::class),
            $appState
        );
    }
}
