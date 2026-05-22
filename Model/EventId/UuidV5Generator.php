<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\EventId;

/**
 * Deterministic UUID v5 generator for AxiTrace Magento order events.
 *
 * MUST stay byte-identical to the Go ingestion-api equivalent (magento_pixel.go)
 * so that client- and server-side ids dedupe at the ad-platform layer (Facebook
 * CAPI, TikTok Events API, Google Ads, GA4 all dedupe by event_id).
 *
 * Algorithm: RFC 4122 §4.3 — SHA-1 of (namespace bytes || input), with the
 * version nibble forced to 5 and the variant nibble forced to 10xxxxxx.
 *
 * @see \claude-work\magento-uuid-parity-proof.md
 */
class UuidV5Generator
{
    /**
     * Shared namespace UUID across all AxiTrace platform integrations.
     * The differentiating element is the input prefix (`magento_order:`), which
     * isolates Magento ids from Shopify (`shopify_order:`) and WooCommerce
     * (`woocommerce_order:`) regardless of numeric id overlap.
     */
    private const NAMESPACE_UUID = '5e5e5e5e-5e5e-5e5e-5e5e-5e5e5e5e5e5e';

    private const ORDER_INPUT_PREFIX = 'magento_order:';

    public function forOrder(string $incrementId): string
    {
        if ($incrementId === '') {
            throw new \InvalidArgumentException('UuidV5Generator: increment id must be non-empty.');
        }

        return self::uuidV5(self::NAMESPACE_UUID, self::ORDER_INPUT_PREFIX . $incrementId);
    }

    /**
     * Computes UUID v5 per RFC 4122 §4.3 without depending on a third-party library
     * (the Magento ecosystem ships ramsey/uuid via dependencies, but we keep this
     * file self-contained so the algorithm is auditable in one place).
     */
    private static function uuidV5(string $namespaceUuid, string $name): string
    {
        $nhex = str_replace(['-', '{', '}'], '', $namespaceUuid);
        if (strlen($nhex) !== 32 || !ctype_xdigit($nhex)) {
            throw new \InvalidArgumentException('UuidV5Generator: invalid namespace UUID.');
        }

        // Convert namespace to its 16-byte binary form.
        $nbin = '';
        for ($i = 0; $i < 32; $i += 2) {
            $nbin .= chr((int) hexdec($nhex[$i] . $nhex[$i + 1]));
        }

        $hash = sha1($nbin . $name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            // Version 5 — set top nibble of time_hi_and_version to 0101.
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            // Variant RFC 4122 — set top two bits of clock_seq_hi to 10.
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }
}
