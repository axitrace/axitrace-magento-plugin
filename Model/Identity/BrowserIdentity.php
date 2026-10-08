<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Identity;

/**
 * The shopper's browser identity, captured from the storefront request that placed
 * the order: the AxiTrace visitor and session ids (vt_vid / vt_sid), the shopper's
 * IP address and User-Agent, the ad platforms' browser ids and every click id the
 * AxiTrace web SDK keeps in a first-party cookie.
 *
 * Without it the purchase reaches AxiTrace with the identity of the Magento server
 * that sends it from the queue consumer: no visitor id (the purchase cannot be
 * stitched to the visitor profile and its ad clicks) and no User-Agent of the buyer.
 *
 * Immutable and framework-free. `fromArray()` is the only way in from storage (the
 * sales_order column, the queue message), so whatever was persisted is filtered to
 * the known keys and to plain, bounded strings again before it is used.
 */
class BrowserIdentity
{
    public const KEY_VISITOR_ID = 'visitor_id';
    public const KEY_SESSION_ID = 'session_id';
    public const KEY_IP         = 'ip';
    public const KEY_USER_AGENT = 'user_agent';

    /**
     * Keys forwarded inside the event's `data` object, named exactly as the AxiTrace
     * event worker reads them (IntegrationForwardingService::prepareEventData()).
     */
    public const SIGNAL_KEYS = [
        'fbp',
        'fbc',
        'ttp',
        'rdt_uuid',
        'obref',
        '_ga',
        'gclid',
        'gbraid',
        'wbraid',
        'ttclid',
        'rdt_cid',
        'oppref',
        'msclkid',
        'twclid',
        'epik',
        'li_fat_id',
        'sccid',
    ];

    /** Upper bound for any single stored value; the User-Agent is the longest one. */
    private const MAX_VALUE_LENGTH = 512;

    /**
     * @param array<string, string> $signals Keyed by SIGNAL_KEYS, non-empty values only.
     */
    private function __construct(
        private readonly string $visitorId,
        private readonly string $sessionId,
        private readonly string $ip,
        private readonly string $userAgent,
        private readonly array $signals,
    ) {
    }

    public static function empty(): self
    {
        return new self('', '', '', '', []);
    }

    /**
     * Rebuilds an identity from its array form, keeping only known keys whose values
     * are non-empty strings (control characters stripped, length capped).
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $signals = [];
        foreach (self::SIGNAL_KEYS as $key) {
            $value = self::clean($data[$key] ?? null);
            if ($value !== '') {
                $signals[$key] = $value;
            }
        }

        return new self(
            self::clean($data[self::KEY_VISITOR_ID] ?? null),
            self::clean($data[self::KEY_SESSION_ID] ?? null),
            self::clean($data[self::KEY_IP] ?? null),
            self::clean($data[self::KEY_USER_AGENT] ?? null),
            $signals,
        );
    }

    /**
     * @return array<string, string> Non-empty values only.
     */
    public function toArray(): array
    {
        $out = [];
        foreach (
            [
                self::KEY_VISITOR_ID => $this->visitorId,
                self::KEY_SESSION_ID => $this->sessionId,
                self::KEY_IP         => $this->ip,
                self::KEY_USER_AGENT => $this->userAgent,
            ] as $key => $value
        ) {
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out + $this->signals;
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * Combines two identities; every non-empty value of $preferred wins.
     */
    public function mergedWith(self $preferred): self
    {
        return self::fromArray($preferred->toArray() + $this->toArray());
    }

    public function visitorId(): string
    {
        return $this->visitorId;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * @return array<string, string> Keyed by SIGNAL_KEYS, non-empty values only.
     */
    public function signals(): array
    {
        return $this->signals;
    }

    private static function clean(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return trim(substr($value, 0, self::MAX_VALUE_LENGTH));
    }
}
