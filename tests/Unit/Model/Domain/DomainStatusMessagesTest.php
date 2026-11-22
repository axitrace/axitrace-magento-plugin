<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Domain;

// Stand-ins for the Magento classes used below (`__()`), so this test also runs
// without a Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Domain\DomainStatusMessages;
use PHPUnit\Framework\TestCase;

/**
 * The merchant reads one plain sentence per storefront host and one for a failed claim.
 */
class DomainStatusMessagesTest extends TestCase
{
    public function testEachHostGetsItsOwnLine(): void
    {
        $lines = (new DomainStatusMessages())->lines([
            'checked_at' => 1,
            'hosts'      => [
                ['host' => 'shop.com', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false],
                ['host' => 'eu.shop.com', 'status' => 'pending', 'reason' => null, 'browser_events_rejected' => false],
                ['host' => 'other.com', 'status' => 'rejected', 'reason' => 'held_by_other_workspace', 'browser_events_rejected' => true],
                ['host' => 'blocked.com', 'status' => 'failed', 'reason' => 'proof_http_status', 'browser_events_rejected' => true],
            ],
            'error'      => null,
        ]);

        self::assertSame(['success', 'warning', 'error', 'error'], array_column($lines, 'level'));
        self::assertStringContainsString('shop.com is verified', $lines[0]['text']);
        self::assertStringContainsString('different AxiTrace workspace', $lines[2]['text']);
        self::assertStringContainsString('https://blocked.com/axitrace/domain/proof', $lines[3]['text']);
    }

    public function testFailedClaimAddsAnErrorLine(): void
    {
        $lines = (new DomainStatusMessages())->lines(['checked_at' => 1, 'hosts' => [], 'error' => 'invalid_secret_key']);

        self::assertCount(1, $lines);
        self::assertSame('error', $lines[0]['level']);
        self::assertStringContainsString('secret key', $lines[0]['text']);
    }

    public function testNeverClaimedSaysItHappensOnSave(): void
    {
        $lines = (new DomainStatusMessages())->lines(null);

        self::assertSame('warning', $lines[0]['level']);
        self::assertStringContainsString('verified automatically', $lines[0]['text']);
    }
}
