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
 * always wins over the stored one.
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
