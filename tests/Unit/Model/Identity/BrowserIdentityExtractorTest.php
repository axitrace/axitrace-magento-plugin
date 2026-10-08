<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Identity;

use AxiTrace\Tracking\Model\Identity\BrowserIdentity;
use AxiTrace\Tracking\Model\Identity\BrowserIdentityExtractor;
use PHPUnit\Framework\TestCase;

/**
 * The cookie rules match the PHP SDK 1.10.0 and the web SDK that wrote the cookies:
 * "v2|<firstSeenMs>|<clickId>" is unwrapped to the bare click id, a click older than
 * its window is dropped, an unversioned value is ignored, and a click id in the URL
 * wins over the stored one unless it replays that very click after its window. Web
 * SDK 0.24.0 added msclkid, twclid, epik, li_fat_id and sccid ("_axi_" cookies) with a
 * read-only fallback to the platforms' own cookies.
 */
class BrowserIdentityExtractorTest extends TestCase
{
    private const NOW_MS = 1_790_000_000_000;
    private const DAY_MS = 86_400_000;
    private const VISITOR = '6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10';
    private const SESSION = '0b9a7c65-1d2e-4f30-8a9b-c1d2e3f4a5b6';

    public function testAxiTraceVisitorAndSessionCookiesAreCaptured(): void
    {
        $identity = $this->extract(['vt_vid' => self::VISITOR, 'vt_sid' => self::SESSION]);

        self::assertSame(self::VISITOR, $identity->visitorId());
        self::assertSame(self::SESSION, $identity->sessionId());
    }

    public function testMalformedVisitorCookieIsDropped(): void
    {
        $identity = $this->extract(['vt_vid' => 'not a visitor id <script>']);

        self::assertSame('', $identity->visitorId());
    }

    public function testVersionedClickIdCookieIsUnwrappedToTheBareId(): void
    {
        $identity = $this->extract([
            '_gclid'   => $this->wrapped('Cj0KCQjwgclid_value-1', 10),
            '_ttclid'  => $this->wrapped('E.C.P.ttclidValue123', 10),
            '_rdt_cid' => $this->wrapped('1234567890123456789_rdtclick', 10),
            '_oppref'  => $this->wrapped('opp_ref_value_42', 10),
            '_gbraid'  => $this->wrapped('0AAAAAgbraid', 10),
            '_wbraid'  => $this->wrapped('CjgKwbraid', 10),
        ]);

        self::assertSame(
            [
                'gclid'   => 'Cj0KCQjwgclid_value-1',
                'gbraid'  => '0AAAAAgbraid',
                'wbraid'  => 'CjgKwbraid',
                'ttclid'  => 'E.C.P.ttclidValue123',
                'rdt_cid' => '1234567890123456789_rdtclick',
                'oppref'  => 'opp_ref_value_42',
            ],
            $identity->signals()
        );
    }

    public function testNinetyDayWindowAppliesToGoogleAndTikTokClicks(): void
    {
        $fresh = $this->extract(['_gclid' => $this->wrapped('gclid-fresh', 89), '_ttclid' => $this->wrapped('tt-ok', 89)]);
        $stale = $this->extract(['_gclid' => $this->wrapped('gclid-stale', 91), '_ttclid' => $this->wrapped('tt-old', 91)]);

        self::assertSame(['gclid' => 'gclid-fresh', 'ttclid' => 'tt-ok'], $fresh->signals());
        self::assertSame([], $stale->signals());
    }

    public function testTwentyEightDayWindowAppliesToRedditAndOpenAiClicks(): void
    {
        $fresh = $this->extract(['_rdt_cid' => $this->wrapped('rdt-fresh', 27), '_oppref' => $this->wrapped('opp-fresh', 27)]);
        $stale = $this->extract(['_rdt_cid' => $this->wrapped('rdt-stale', 29), '_oppref' => $this->wrapped('opp-stale', 29)]);

        self::assertSame(['rdt_cid' => 'rdt-fresh', 'oppref' => 'opp-fresh'], $fresh->signals());
        self::assertSame([], $stale->signals());
    }

    public function testUnversionedOrMalformedClickIdCookiesAreIgnored(): void
    {
        $identity = $this->extract([
            '_gclid'   => 'legacy-bare-gclid',
            '_ttclid'  => 'v2|notanumber|abc',
            '_rdt_cid' => 'v2|0|abc',
            '_oppref'  => 'v2|' . (self::NOW_MS - self::DAY_MS) . '|',
            '_gbraid'  => 'v2|' . (self::NOW_MS - self::DAY_MS),
        ]);

        self::assertSame([], $identity->signals());
    }

    public function testClickIdInTheUrlWinsOverTheStoredOne(): void
    {
        $identity = $this->extract(
            ['_gclid' => $this->wrapped('stored-gclid', 5)],
            ['gclid' => 'fresh-url-gclid']
        );

        self::assertSame('fresh-url-gclid', $identity->signals()['gclid']);
    }

    public function testInvalidUrlClickIdFallsBackToTheStoredOne(): void
    {
        $identity = $this->extract(
            ['_gclid' => $this->wrapped('stored-gclid', 5)],
            ['gclid' => 'has space']
        );

        self::assertSame('stored-gclid', $identity->signals()['gclid']);
    }

    /**
     * The five click ids added in web SDK 0.24.0: key, SDK cookie, max age, a realistic id.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function newClickIds(): array
    {
        return [
            'msclkid'   => ['msclkid', '_axi_msclkid', 90, 'a1b2c3d4e5f60718293a4b5c6d7e8f90'],
            'twclid'    => ['twclid', '_axi_twclid', 90, '2-7abc1def2ghi3jkl4mno5pqr'],
            'epik'      => ['epik', '_axi_epik', 60, 'dj0yJnU9c2FtcGxlRXBpa1ZhbHVl'],
            'li_fat_id' => ['li_fat_id', '_axi_li_fat_id', 30, 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'],
            'sccid'     => ['sccid', '_axi_sccid', 28, 'b2a1f3c4-5d6e-4f80-9a1b-2c3d4e5f6a7b'],
        ];
    }

    public function testClickIdTablesMatchTheWebSdk(): void
    {
        $expected = [
            'gclid'   => ['_gclid', 90],
            'gbraid'  => ['_gbraid', 90],
            'wbraid'  => ['_wbraid', 90],
            'ttclid'  => ['_ttclid', 90],
            'rdt_cid' => ['_rdt_cid', 28],
            'oppref'  => ['_oppref', 28],
        ];
        foreach (self::newClickIds() as [$key, $cookie, $days]) {
            $expected[$key] = [$cookie, $days];
        }

        self::assertSame($expected, BrowserIdentityExtractor::CLICK_ID_COOKIES);
        self::assertSame(['sccid' => ['ScCid', 'sccid']], BrowserIdentityExtractor::CLICK_ID_URL_PARAMS);
        self::assertSame(
            ['msclkid' => '_uetmsclkid', 'twclid' => '_twclid', 'epik' => '_epik', 'li_fat_id' => 'li_fat_id'],
            BrowserIdentityExtractor::VENDOR_CLICK_ID_COOKIES
        );
        foreach (array_keys($expected) as $key) {
            self::assertContains($key, BrowserIdentity::SIGNAL_KEYS, 'stored and forwarded: ' . $key);
        }
    }

    /**
     * @dataProvider newClickIds
     */
    public function testNewClickIdCookieIsUnwrapped(string $key, string $cookie, int $days, string $id): void
    {
        self::assertSame([$key => $id], $this->extract([$cookie => $this->wrapped($id, 1)])->signals());
    }

    /**
     * @dataProvider newClickIds
     */
    public function testNewClickIdFromTheUrl(string $key, string $cookie, int $days, string $id): void
    {
        self::assertSame([$key => $id], $this->extract([], [$key => $id])->signals());
    }

    /**
     * @dataProvider newClickIds
     */
    public function testNewClickIdUrlWinsOverTheCookie(string $key, string $cookie, int $days, string $id): void
    {
        $identity = $this->extract([$cookie => $this->wrapped('stored-click-1', 1)], [$key => $id]);

        self::assertSame([$key => $id], $identity->signals());
    }

    /**
     * @dataProvider newClickIds
     */
    public function testNewClickIdWindowIsTheWebSdkMaximumAge(string $key, string $cookie, int $days, string $id): void
    {
        self::assertSame([$key => $id], $this->extract([$cookie => $this->wrapped($id, $days)])->signals());
        self::assertSame([], $this->extract([$cookie => $this->wrapped($id, $days + 1)])->signals());
    }

    /**
     * @dataProvider newClickIds
     */
    public function testNewClickIdMalformedCookieIsIgnored(string $key, string $cookie, int $days, string $id): void
    {
        foreach ([$id, 'v2|abc|' . $id, 'v2|0|' . $id, 'v2|' . self::NOW_MS . '|', 'v2|' . self::NOW_MS . '|a b'] as $raw) {
            self::assertSame([], $this->extract([$cookie => $raw])->signals(), $raw);
        }
    }

    /**
     * A bookmarked landing URL replaying the stored click after its window is not a new
     * ad click (web SDK isKnownStaleCookie), for every click id.
     */
    public function testUrlReplayingAnExpiredOrLegacyStoredClickIsDropped(): void
    {
        foreach (BrowserIdentityExtractor::CLICK_ID_COOKIES as $key => [$cookie, $days]) {
            $param = BrowserIdentityExtractor::CLICK_ID_URL_PARAMS[$key][0] ?? $key;

            $expired = $this->extract([$cookie => $this->wrapped('replayed-click', $days + 1)], [$param => 'replayed-click']);
            $legacy = $this->extract([$cookie => 'replayed-click'], [$param => 'replayed-click']);
            $newClick = $this->extract([$cookie => $this->wrapped('old-click', $days + 1)], [$param => 'new-click']);

            self::assertSame([], $expired->signals(), $key . ' expired');
            self::assertSame([], $legacy->signals(), $key . ' legacy');
            self::assertSame([$key => 'new-click'], $newClick->signals(), $key . ' new click');
        }
    }

    public function testSnapClickIdIsReadFromScCidBeforeSccid(): void
    {
        self::assertSame(['sccid' => 'snap-capital'], $this->extract([], ['ScCid' => 'snap-capital'])->signals());
        self::assertSame(
            ['sccid' => 'snap-capital'],
            $this->extract([], ['ScCid' => 'snap-capital', 'sccid' => 'snap-lower'])->signals()
        );
        self::assertSame(
            ['sccid' => 'snap-lower'],
            $this->extract([], ['ScCid' => 'has space', 'sccid' => 'snap-lower'])->signals()
        );
    }

    /**
     * Platform cookie fallback: key, vendor cookie, raw value as the platform's tag
     * writes it, the bare id.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function vendorCookies(): array
    {
        return [
            'msclkid with the UET prefix' => ['msclkid', '_uetmsclkid', '_ueta1b2c3d4e5f60718293a4b5c6d7e8f90', 'a1b2c3d4e5f60718293a4b5c6d7e8f90'],
            'msclkid bare'                => ['msclkid', '_uetmsclkid', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'a1b2c3d4e5f60718293a4b5c6d7e8f90'],
            'twclid X pixel JSON'         => ['twclid', '_twclid', '{"twclid":"2-7abc1def2ghi3jkl4mno5pqr","timestamp":1789990000000}', '2-7abc1def2ghi3jkl4mno5pqr'],
            'twclid bare'                 => ['twclid', '_twclid', '2-7abc1def2ghi3jkl4mno5pqr', '2-7abc1def2ghi3jkl4mno5pqr'],
            'epik bare'                   => ['epik', '_epik', 'dj0yJnU9c2FtcGxlRXBpa1ZhbHVl', 'dj0yJnU9c2FtcGxlRXBpa1ZhbHVl'],
            'li_fat_id bare'              => ['li_fat_id', 'li_fat_id', 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'],
        ];
    }

    /**
     * @dataProvider vendorCookies
     */
    public function testPlatformCookieIsTheLastFallback(string $key, string $vendor, string $raw, string $id): void
    {
        [$sdkCookie, $days] = BrowserIdentityExtractor::CLICK_ID_COOKIES[$key];

        self::assertSame([$key => $id], $this->extract([$vendor => $raw])->signals(), 'alone');
        self::assertSame(
            [$key => $id],
            $this->extract([$vendor => $raw, $sdkCookie => $this->wrapped('expired-click', $days + 1)])->signals(),
            'expired SDK cookie'
        );
        self::assertSame(
            [$key => 'sdk-click'],
            $this->extract([$vendor => $raw, $sdkCookie => $this->wrapped('sdk-click', 1)])->signals(),
            'SDK cookie wins'
        );
        self::assertSame(
            [$key => 'url-click'],
            $this->extract([$vendor => $raw], [$key => 'url-click'])->signals(),
            'URL wins'
        );
        self::assertSame(
            [],
            $this->extract(
                [$vendor => $raw, $sdkCookie => $this->wrapped('replayed-click', $days + 1)],
                [$key => 'replayed-click']
            )->signals(),
            'a replayed bookmark never falls back to the platform cookie'
        );
    }

    public function testMalformedPlatformCookiesAreIgnored(): void
    {
        foreach ([
            '_uetmsclkid' => ['_uet', '_uetabc def', str_repeat('a', 501)],
            '_twclid'     => ['{not json', '{"other":"2-7abc"}', '{"twclid":12345678}', '{"twclid":"a b"}'],
            '_epik'       => ['', 'a<b>'],
            'li_fat_id'   => ['has a space', 'v2|1789990000000|wrapped'],
        ] as $cookie => $values) {
            foreach ($values as $raw) {
                self::assertSame([], $this->extract([$cookie => $raw])->signals(), $cookie . '=' . $raw);
            }
        }
    }

    public function testSnapHasNoPlatformCookieFallback(): void
    {
        self::assertSame([], $this->extract(['_scid' => 'b2a1f3c4-5d6e', 'sccid' => 'b2a1f3c4-5d6e'])->signals());
    }

    public function testBrowserIdsAreValidatedAndForwardedUnderTheirWorkerNames(): void
    {
        $identity = $this->extract([
            '_fbp'      => 'fb.1.1700000000000.1234567890',
            '_fbc'      => 'fb.1.1700000000000.IwAR_click-id',
            '_ttp'      => '01KCFX1BV9NB74R5ZE592YDTYN_.tt.1',
            '_rdt_uuid' => '1700000000000.550e8400-e29b-41d4-a716-446655440000',
            '__obref'   => '550e8400-e29b-41d4-a716-446655440000',
            '_ga'       => 'GA1.2.123456789.1700000000',
        ]);

        self::assertSame(
            [
                'fbp'      => 'fb.1.1700000000000.1234567890',
                'fbc'      => 'fb.1.1700000000000.IwAR_click-id',
                'ttp'      => '01KCFX1BV9NB74R5ZE592YDTYN_.tt.1',
                'rdt_uuid' => '1700000000000.550e8400-e29b-41d4-a716-446655440000',
                'obref'    => '550e8400-e29b-41d4-a716-446655440000',
                '_ga'      => 'GA1.2.123456789.1700000000',
            ],
            $identity->signals()
        );
    }

    public function testMalformedBrowserIdsAreDropped(): void
    {
        $identity = $this->extract([
            '_fbp'      => 'garbage',
            '_fbc'      => 'fb.1.x.y',
            '_rdt_uuid' => 'not-a-uuid',
            '_ga'       => 'GA1.2.abc',
        ]);

        self::assertSame([], $identity->signals());
    }

    public function testIpAndUserAgentAreCaptured(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/605.1.15';
        $identity = (new BrowserIdentityExtractor())->extract(
            static fn (string $n): ?string => null,
            static fn (string $n): ?string => null,
            '2001:db8::7',
            $ua,
            self::NOW_MS
        );

        self::assertSame('2001:db8::7', $identity->ip());
        self::assertSame($ua, $identity->userAgent());
    }

    public function testInvalidIpIsDroppedAndUserAgentIsCapped(): void
    {
        $identity = (new BrowserIdentityExtractor())->extract(
            static fn (string $n): ?string => null,
            static fn (string $n): ?string => null,
            'unknown',
            str_repeat('a', 2000),
            self::NOW_MS
        );

        self::assertSame('', $identity->ip());
        self::assertSame(512, strlen($identity->userAgent()));
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $query
     */
    private function extract(array $cookies, array $query = []): BrowserIdentity
    {
        return (new BrowserIdentityExtractor())->extract(
            static fn (string $name): ?string => $cookies[$name] ?? null,
            static fn (string $name): ?string => $query[$name] ?? null,
            null,
            null,
            self::NOW_MS
        );
    }

    private function wrapped(string $clickId, int $ageDays): string
    {
        return 'v2|' . (self::NOW_MS - $ageDays * self::DAY_MS) . '|' . $clickId;
    }
}
