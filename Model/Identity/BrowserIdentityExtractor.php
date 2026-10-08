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
 *   - Click ids (gclid, gbraid, wbraid, ttclid, rdt_cid, oppref): a value in the
 *     current URL wins; otherwise the first-party cookie the web SDK persisted
 *     (`_gclid`, `_gbraid`, `_wbraid`, `_ttclid` for 90 days, `_rdt_cid`, `_oppref`
 *     for 28 days). Cookie values have the format "v2|<firstSeenMs>|<clickId>"; only
 *     the bare click id is kept. An unversioned value (which the web SDK itself
 *     deletes on read), a missing or non-numeric timestamp, an empty id, and a click
 *     older than its maximum age are all ignored, so the server never replays a click
 *     the browser would no longer send.
 *   - Browser ids (vt_vid, vt_sid, _fbp, _fbc, _ttp, _rdt_uuid, __obref, _ga) are
 *     kept only when they match the format their writer produces; the patterns mirror
 *     the WooCommerce plugin's BrowserIdentifiers and the event worker's
 *     UserIdentityService, so a malformed or spoofed cookie is dropped here.
 *   - IP must be a valid IPv4/IPv6 address; the User-Agent is kept as sent, capped.
 */
class BrowserIdentityExtractor
{
    /**
     * Click-id cookies written by the web SDK: param name => [cookie name, max age in days].
     */
    public const CLICK_ID_COOKIES = [
        'gclid'   => ['_gclid', 90],
        'gbraid'  => ['_gbraid', 90],
        'wbraid'  => ['_wbraid', 90],
        'ttclid'  => ['_ttclid', 90],
        'rdt_cid' => ['_rdt_cid', 28],
        'oppref'  => ['_oppref', 28],
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

        foreach (self::CLICK_ID_COOKIES as $param => [$cookieName, $maxAgeDays]) {
            $clickId = $this->validClickId($query($param))
                ?? $this->clickIdFromCookie($cookie($cookieName), $maxAgeDays, $nowMs);
            if ($clickId !== null) {
                $found[$param] = $clickId;
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
     * The bare click id held in a web SDK cookie ("v2|<firstSeenMs>|<clickId>"), or
     * null when the value is unversioned, malformed, empty or older than $maxAgeDays.
     */
    private function clickIdFromCookie(?string $raw, int $maxAgeDays, int $nowMs): ?string
    {
        if ($raw === null || strpos($raw, self::CLICK_ID_COOKIE_VERSION_PREFIX) !== 0) {
            return null;
        }

        $parts = explode('|', $raw, 3);
        if (count($parts) !== 3 || preg_match('/^\d{1,15}$/', $parts[1]) !== 1) {
            return null;
        }

        $firstSeenMs = (int) $parts[1];
        if ($firstSeenMs <= 0 || $nowMs - $firstSeenMs > $maxAgeDays * 86400000) {
            return null;
        }

        return $this->validClickId($parts[2]);
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
