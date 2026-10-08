<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Queue;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Identity\BrowserIdentity;
use AxiTrace\Tracking\Model\Queue\OrderEventPublisher;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * The queue message is the only channel between the request-scoped observer and
 * the asynchronous consumer, so the consent state has to survive it.
 */
class OrderEventPublisherTest extends TestCase
{
    public function testConsentTravelsInTheQueueMessage(): void
    {
        $payload = $this->publishWith('denied');

        self::assertSame('denied', $payload['consent']);
    }

    public function testGrantedTravelsInTheQueueMessage(): void
    {
        $payload = $this->publishWith('granted');

        self::assertSame('granted', $payload['consent']);
    }

    public function testNoConsentKeepsTodaysPayloadShape(): void
    {
        $payload = $this->publishWith(null);

        self::assertArrayNotHasKey('consent', $payload);
        self::assertSame(
            ['order_id', 'increment_id', 'store_id', 'event_id_hash', 'state'],
            array_keys($payload)
        );
    }

    public function testBrowserIdentityTravelsInTheQueueMessage(): void
    {
        $payload = $this->publishWith(null, BrowserIdentity::fromArray([
            'visitor_id' => '6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10',
            'gclid'      => 'Cj0KCQjw-gclid',
        ]));

        self::assertSame(
            ['visitor_id' => '6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10', 'gclid' => 'Cj0KCQjw-gclid'],
            $payload['browser']
        );
    }

    public function testEmptyBrowserIdentityKeepsTodaysPayloadShape(): void
    {
        $payload = $this->publishWith(null, BrowserIdentity::empty());

        self::assertArrayNotHasKey('browser', $payload);
    }

    public function testUnknownConsentValueIsNotForwarded(): void
    {
        $payload = $this->publishWith('maybe');

        self::assertArrayNotHasKey('consent', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishWith(?string $consent, ?BrowserIdentity $browser = null): array
    {
        $captured = null;

        $queue = $this->createMock(PublisherInterface::class);
        $queue->method('publish')->willReturnCallback(
            static function (string $topic, string $message) use (&$captured) {
                $captured = $message;

                return null;
            }
        );

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getStoreId')->willReturn(3);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);

        (new OrderEventPublisher($queue))->publishOrder($order, 'hash-1', $browser, $consent);

        self::assertIsString($captured);

        return json_decode((string) $captured, true, 8, JSON_THROW_ON_ERROR);
    }
}
