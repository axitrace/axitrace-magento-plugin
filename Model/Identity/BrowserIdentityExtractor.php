<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Identity;

/**
 * Turns the raw values of one storefront request (cookies, query parameters, IP,
 * User-Agent) into a validated BrowserIdentity. A pure function of its inputs:
 * no Magento class is referenced, so every rule is unit-testable without Magento.
 * The Magento-specific reads live in BrowserIdentityCapture.
 *
 * Rules, mirroring the AxiTrace PHP SDK 1.10.0 (AxiTrace::autoDetectAttribution())
 * and the web SDK that wrote the cookies:
 *   - Click ids (gclid, gbraid, wbraid, ttclid, rdt_cid, oppref, msclkid, twclid,
 *     epik, li_fat_id, sccid), as the web SDK 0.24.0 applies them
 *     (PERSISTED_CLICK_IDS / applyPersistedClickId in velitrack-sdk.js):
 *       1. a value in the current URL wins (Snap: `ScCid`, then `sccid`), unless the
 *          SDK cookie already holds that very click past its maximum age (or in the
 *          unversioned legacy format): then the URL is a replayed bookmark, not a new
 *          ad click, and nothing is sent;
 *       2. otherwise the first-party cookie the web SDK persisted (`_gclid`,
 *          `_gbraid`, `_wbraid`, `_ttclid`, `_axi_msclkid`, `_axi_twclid` for 90 days,
 *          `_axi_epik` for 60, `_axi_li_fat_id` for 30, `_rdt_cid`, `_oppref`,
 *          `_axi_sccid` for 28). Cookie values have the format
 *          "v2|<firstSeenMs>|<clickId>"; only the bare click id is kept. An
 *          unversioned value (which the web SDK itself deletes on read), a missing or
 *          non-numeric timestamp, an empty id, and a click older than its maximum age
 *          are all ignored, so the server never replays a click the browser would no
 *          longer send;
 *       3. otherwise, for Microsoft, X, Pinterest and LinkedIn, the cookie the
 *          platform's own tag writes (VENDOR_CLICK_ID_COOKIES), read only.
 *   - Browser ids (vt_vid, vt_sid, _fbp, _fbc, _ttp, _rdt_uuid, __obref, _ga) are
 *     kept only when they match the format their writer produces; the patterns mirror
 *     the WooCommerce plugin's BrowserIdentifiers and the event worker's
 *     UserIdentityService, so a malformed or spoofed cookie is dropped here.
 *   - IP must be a valid IPv4/IPv6 address; the User-Agent is kept as sent, capped.
 */
class BrowserIdentityExtractor
{
    /**
     * Click-id cookies written by the web SDK: click id key => [cookie name, max age in
     * days]. The five added in web SDK 0.24.0 carry an "_axi_" prefix because the plain
     * names belong to the platforms' own tags.
     */
    public const CLICK_ID_COOKIES = [
        'gclid'     => ['_gclid', 90],
        'gbraid'    => ['_gbraid', 90],
        'wbraid'    => ['_wbraid', 90],
        'ttclid'    => ['_ttclid', 90],
        'rdt_cid'   => ['_rdt_cid', 28],
        'oppref'    => ['_oppref', 28],
        'msclkid'   => ['_axi_msclkid', 90],
        'twclid'    => ['_axi_twclid', 90],
        'epik'      => ['_axi_epik', 60],
        'li_fat_id' => ['_axi_li_fat_id', 30],
        'sccid'     => ['_axi_sccid', 28],
    ];

    /**
     * URL parameters, in priority order, of a click id whose parameter differs from its
     * key: Snap's own parameter is "ScCid" (query keys are case-sensitive).
     */
    public const CLICK_ID_URL_PARAMS = [
        'sccid' => ['ScCid', 'sccid'],
    ];

    /**
     * The cookie the platform's own tag keeps the click id in (UET, the X pixel, the
     * Pinterest tag, the LinkedIn Insight Tag), read as the last fallback and never
     * written. Snap documents no such cookie.
     */
    public const VENDOR_CLICK_ID_COOKIES = [
        'msclkid'   => '_uetmsclkid',
        'twclid'    => '_twclid',
        'epik'      => '_epik',
        'li_fat_id' => 'li_fat_id',
    ];

    /** Version prefix of the click-id cookie format written by the web SDK. */
    private const CLICK_ID_COOKIE_VERSION_PREFIX = 'v2|';

    /** Maximum length of a forwarded click id (same bound as the PHP SDK). */
    private const MAX_CLICK_ID_LENGTH = 500;

    /**
     * A click id is an opaque token: printable, no whitespace, never a "|" (that would
     * be a still-wrapped cookie value), no quotes, angle brackets or backslashes.
     */
    private const CLICK_ID_PATTERN = '/^[^\s|<>"\'\\\\]{1,500}$/';

    /** AxiTrace visitor/session cookies (vt_vid, vt_sid): UUIDs written by the web SDK. */
    private const AXITRACE_ID_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    /** Meta browser id: fb.<subdomainIndex>.<creationTimeMs>.<random>. */
    private const FBP_PATTERN = '/^fb\.\d+\.\d+\.\d+$/';

    /** Meta click id cookie: fb.<subdomainIndex>.<creationTimeMs>.<fbclid>. */
    private const FBC_PATTERN = '/^fb\.\d+\.\d+\.[A-Za-z0-9_-]+$/';

    /** TikTok browser id, e.g. "01KCFX1BV9NB74R5ZE592YDTYN_.tt.1". */
    private const TTP_PATTERN = '/^[A-Za-z0-9_.-]{8,128}$/';

    /** Reddit browser id, optionally prefixed with its creation timestamp. */
    private const RDT_UUID_PATTERN =
        '/^(\d{10,16}\.)?[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    /** OpenAI Ads browser reference (__obref, a UUID). */
    private const OBREF_PATTERN = '/^[A-Za-z0-9_.-]{8,128}$/';

    /** Google Analytics client cookie: GA1.2.<random>.<firstVisitTs>. */
    private const GA_PATTERN = '/^GA\d+\.\d+\.\d+\.\d+$/';

    /** Upper bound for a forwarded User-Agent string (defensive cap, not a spec limit). */
    private const MAX_USER_AGENT_LENGTH = 512;

    /**
     * Browser-id cookies: identity key => [cookie name, pattern].
     */
    private const BROWSER_ID_COOKIES = [
        BrowserIdentity::KEY_VISITOR_ID => ['vt_vid', self::AXITRACE_ID_PATTERN],
        BrowserIdentity::KEY_SESSION_ID => ['vt_sid', self::AXITRACE_ID_PATTERN],
        'fbp'                           => ['_fbp', self::FBP_PATTERN],
        'fbc'                           => ['_fbc', self::FBC_PATTERN],
        'ttp'                           => ['_ttp', self::TTP_PATTERN],
        'rdt_uuid'                      => ['_rdt_uuid', self::RDT_UUID_PATTERN],
        'obref'                         => ['__obref', self::OBREF_PATTERN],
        '_ga'                           => ['_ga', self::GA_PATTERN],
    ];

    /**
     * @param callable(string): ?string $cookie   Returns the decoded value of one cookie, or null.
     * @param callable(string): ?string $query    Returns one query-string parameter of the URL, or null.
     * @param string|null               $ip        The shopper's IP address as Magento resolved it.
     * @param string|null               $userAgent The shopper's User-Agent header.
     * @param int                       $nowMs     Current time in milliseconds since the epoch.
     */
    public function extract(
        callable $cookie,
        callable $query,
        ?string $ip,
        ?string $userAgent,
        int $nowMs
    ): BrowserIdentity {
        $found = [];

        foreach (self::BROWSER_ID_COOKIES as $key => [$cookieName, $pattern]) {
            $value = $cookie($cookieName);
            if (is_string($value) && preg_match($pattern, $value) === 1) {
                $found[$key] = $value;
            }
        }

        foreach (self::CLICK_ID_COOKIES as $key => [$cookieName, $maxAgeDays]) {
            $clickId = $this->clickId($key, $cookie, $query, $cookieName, $maxAgeDays, $nowMs);
            if ($clickId !== null) {
                $found[$key] = $clickId;
            }
        }

        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $found[BrowserIdentity::KEY_IP] = $ip;
        }

        if (is_string($userAgent)) {
            $userAgent = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $userAgent));
            if ($userAgent !== '') {
                $found[BrowserIdentity::KEY_USER_AGENT] = substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);
            }
        }

        return BrowserIdentity::fromArray($found);
    }

    /**
     * One click id of the request, or null (rules 1-3 in the class docblock).
     *
     * @param callable(string): ?string $cookie
     * @param callable(string): ?string $query
     */
    private function clickId(
        string $key,
        callable $cookie,
        callable $query,
        string $cookieName,
        int $maxAgeDays,
        int $nowMs
    ): ?string {
        $stored = $cookie($cookieName);

        foreach (self::CLICK_ID_URL_PARAMS[$key] ?? [$key] as $param) {
            $fromUrl = $this->validClickId($query($param));
            if ($fromUrl !== null) {
                return $this->isKnownStaleCookie($stored, $fromUrl, $maxAgeDays, $nowMs) ? null : $fromUrl;
            }
        }

        $parsed = $this->parseClickIdCookie($stored);
        if ($parsed !== null && !$this->isExpired($parsed['firstSeenMs'], $maxAgeDays, $nowMs)) {
            return $parsed['clickId'];
        }

        $vendorCookie = self::VENDOR_CLICK_ID_COOKIES[$key] ?? null;

        return $vendorCookie !== null ? $this->vendorClickId($vendorCookie, $cookie($vendorCookie)) : null;
    }

    /**
     * True when the SDK cookie already holds this very click past its maximum age, or
     * holds it in the unversioned legacy format whose age is unknown: the URL is then a
     * bookmarked or shared link replayed after the click window (web SDK
     * isKnownStaleCookie).
     */
    private function isKnownStaleCookie(?string $raw, string $clickId, int $maxAgeDays, int $nowMs): bool
    {
        if ($raw === null || $raw === '') {
            return false;
        }

        if (strpos($raw, self::CLICK_ID_COOKIE_VERSION_PREFIX) !== 0) {
            return trim($raw) === $clickId;
        }

        $parsed = $this->parseClickIdCookie($raw);

        return $parsed !== null
            && $parsed['clickId'] === $clickId
            && $this->isExpired($parsed['firstSeenMs'], $maxAgeDays, $nowMs);
    }

    /**
     * A web SDK cookie ("v2|<firstSeenMs>|<clickId>") split into its first-seen time and
     * bare click id, or null when the value is unversioned, malformed or empty. No age
     * check.
     *
     * @return array{firstSeenMs: int, clickId: string}|null
     */
    private function parseClickIdCookie(?string $raw): ?array
    {
        if ($raw === null || strpos($raw, self::CLICK_ID_COOKIE_VERSION_PREFIX) !== 0) {
            return null;
        }

        $parts = explode('|', $raw, 3);
        if (count($parts) !== 3 || preg_match('/^\d{1,15}$/', $parts[1]) !== 1) {
            return null;
        }

        $firstSeenMs = (int) $parts[1];
        $clickId = $this->validClickId($parts[2]);
        if ($firstSeenMs <= 0 || $clickId === null) {
            return null;
        }

        return ['firstSeenMs' => $firstSeenMs, 'clickId' => $clickId];
    }

    private function isExpired(int $firstSeenMs, int $maxAgeDays, int $nowMs): bool
    {
        return $nowMs - $firstSeenMs > $maxAgeDays * 86400000;
    }

    /**
     * The click id inside a platform-owned cookie, or null. Formats, as the web SDK's
     * readVendorClickId() reads them:
     *   _uetmsclkid      - UET writes "_uet" + msclkid; a bare msclkid is accepted too;
     *   _twclid          - the X pixel writes JSON {"twclid": "...", ...}; the X
     *                      server-side tag writes the bare twclid;
     *   _epik, li_fat_id - the bare click id.
     */
    private function vendorClickId(string $cookieName, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);
        if ($cookieName === '_uetmsclkid' && strpos($value, '_uet') === 0) {
            $value = substr($value, 4);
        } elseif ($cookieName === '_twclid' && strpos($value, '{') === 0) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) && isset($decoded['twclid']) && is_string($decoded['twclid'])
                ? $decoded['twclid']
                : '';
        }

        // The web SDK rejects an over-long platform cookie instead of truncating it.
        if (strlen($value) > self::MAX_CLICK_ID_LENGTH) {
            return null;
        }

        return $this->validClickId($value);
    }

    private function validClickId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(substr($value, 0, self::MAX_CLICK_ID_LENGTH));

        return preg_match(self::CLICK_ID_PATTERN, $value) === 1 ? $value : null;
    }
}
