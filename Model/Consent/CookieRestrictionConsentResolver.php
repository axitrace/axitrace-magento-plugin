<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Consent;

/**
 * Turns Magento's Cookie Restriction Mode into the consent state AxiTrace stores
 * on the event (`data.consent`), as a pure function of four inputs.
 *
 * Deliberately framework-free: no Magento class is referenced here, so every
 * branch is unit-testable without a Magento installation. The Magento-specific
 * reads (the `web/cookie/cookie_restriction` flag, the cookie, the area code and
 * the website id) stay in OrderStateTransitionObserver, which is the only
 * request-scoped point of the purchase dispatch flow.
 *
 * The rules, in order:
 *   1. Cookie Restriction Mode off  -> no consent state at all (null). The shop
 *      asks for no consent, so the workspace consent policy in AxiTrace decides.
 *   2. Outside the `frontend` area  -> no consent state at all (null). An admin
 *      invoice, a payment webhook or a cron run carries no buyer cookies, so
 *      "cookie absent" proves nothing there. Never `denied` in those contexts.
 *   3. Frontend, restriction on, cookie carries a truthy entry for the current
 *      website -> `granted`.
 *   4. Frontend, restriction on, no cookie or no truthy entry for this website
 *      -> `denied`.
 *
 * Cookie shape: Magento writes `user_allowed_save_cookie` as a JSON object keyed
 * by website id, e.g. {"1":1}, and `Magento\Cookie\Helper\Cookie` reads it the
 * same way (a truthy entry for the CURRENT website means "allowed"). Consent
 * given on one website therefore does not leak to another.
 */
final class CookieRestrictionConsentResolver
{
    /** The only value meaning "the visitor accepted cookies". */
    public const DECISION_GRANTED = 'granted';

    /** The only value meaning "the visitor did not accept cookies". */
    public const DECISION_DENIED = 'denied';

    /** The cookie Magento sets when the visitor clicks "Allow Cookies". */
    public const COOKIE_NAME = 'user_allowed_save_cookie';

    /** The only Magento area where a missing cookie is evidence of a refusal. */
    public const AREA_FRONTEND = 'frontend';

    /** Magento system config path of the Cookie Restriction Mode flag. */
    public const XML_PATH_COOKIE_RESTRICTION = 'web/cookie/cookie_restriction';

    /**
     * Values that are present but do NOT mean consent, for the rare consent
     * manager that replaces Magento's JSON object with a bare marker.
     * Mirrors ConsentGate::DENY_VALUES in the Shopware plugin.
     */
    private const DENY_VALUES = ['0', 'false', 'no', 'deny', 'denied', 'opt-out', 'null'];

    /**
     * @param bool        $restrictionEnabled Value of `web/cookie/cookie_restriction` for this store.
     * @param string|null $cookieValue        Raw `user_allowed_save_cookie` value, or null when absent.
     * @param string|null $areaCode           Current Magento area code, or null when it is not set.
     * @param int         $websiteId          Website id the order belongs to.
     *
     * @return string|null `granted`, `denied`, or null for "this request states nothing".
     */
    public function resolve(
        bool $restrictionEnabled,
        ?string $cookieValue,
        ?string $areaCode,
        int $websiteId
    ): ?string {
        if (!$restrictionEnabled) {
            return null;
        }

        if ($areaCode !== self::AREA_FRONTEND) {
            return null;
        }

        return $this->isGrantSignal($cookieValue, $websiteId)
            ? self::DECISION_GRANTED
            : self::DECISION_DENIED;
    }

    /**
     * Does this cookie value mean "this visitor allowed cookies on this website"?
     */
    private function isGrantSignal(?string $cookieValue, int $websiteId): bool
    {
        if ($cookieValue === null) {
            return false;
        }

        $raw = trim($cookieValue);
        if ($raw === '') {
            return false;
        }

        $decoded = $this->decodeJson($raw);

        if ($decoded === null) {
            // Not JSON at all (a literal `null` lands here too, and the deny-list
            // covers it). Magento never writes this shape, so treat any value
            // that is not an explicit refusal as the visitor's accept click, which
            // is exactly what the browser SDK adapter does for the same cookie.
            return !in_array(strtolower($raw), self::DENY_VALUES, true);
        }

        if (is_array($decoded)) {
            // PHP casts the numeric string keys of the decoded object to integers.
            return !empty($decoded[$websiteId]);
        }

        // A bare JSON scalar: 1 / true grant, 0 / false / "" deny.
        return !empty($decoded);
    }

    /**
     * Decodes the cookie value as JSON, retrying once on the URL-encoded form for
     * stacks that hand the value over still percent-encoded.
     *
     * @return array<array-key, mixed>|scalar|null Null when the value is not JSON.
     */
    private function decodeJson(string $raw)
    {
        foreach ($this->jsonCandidates($raw) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function jsonCandidates(string $raw): array
    {
        $candidates = [$raw];

        if (str_contains($raw, '%')) {
            $urlDecoded = rawurldecode($raw);
            if ($urlDecoded !== $raw) {
                $candidates[] = $urlDecoded;
            }
        }

        return $candidates;
    }
}
