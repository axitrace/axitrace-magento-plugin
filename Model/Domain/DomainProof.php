<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Domain;

/**
 * The answer to AxiTrace's store domain proof request.
 *
 * When the module claims a storefront host (StoreDomainClaimer), AxiTrace fetches
 * `https://<host>/axitrace/domain/proof?nonce=<nonce>` with a one-time nonce. The
 * answer names the workspace public key configured for the store view (public by
 * design: every storefront page carries it) and, with a secret key, an HMAC of
 * the nonce, the claimed host and the public key. Only a store configured with
 * the workspace's keys can produce it, so no DNS change is needed.
 *
 * The body carries exactly the keys AxiTrace accepts; any other key fails the proof.
 */
class DomainProof
{
    public const PLATFORM = 'magento';

    private const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/';

    /**
     * @return array{status: int, body: array<string, int|string>}
     */
    public static function answer(string $nonce, string $publicKey, string $secretKey, string $host): array
    {
        if (preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            return ['status' => 400, 'body' => ['error' => 'invalid_nonce']];
        }
        if ($publicKey === '') {
            return ['status' => 404, 'body' => ['error' => 'not_configured']];
        }

        $body = [
            'axitrace_domain_proof' => 1,
            'public_key'            => $publicKey,
            'nonce'                 => $nonce,
            'platform'              => self::PLATFORM,
        ];
        if ($secretKey !== '' && $host !== '') {
            $body['signature'] = self::signature($secretKey, $nonce, $host, $publicKey);
        }

        return ['status' => 200, 'body' => $body];
    }

    public static function signature(string $secretKey, string $nonce, string $host, string $publicKey): string
    {
        return hash_hmac('sha256', $nonce . '|' . $host . '|' . $publicKey, $secretKey);
    }
}
