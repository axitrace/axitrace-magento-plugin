<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Queue;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

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

    public function testUnknownConsentValueIsNotForwarded(): void
    {
        $payload = $this->publishWith('maybe');

        self::assertArrayNotHasKey('consent', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishWith(?string $consent): array
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

        (new OrderEventPublisher($queue))->publishOrder($order, 'hash-1', null, null, $consent);

        self::assertIsString($captured);

        return json_decode((string) $captured, true, 8, JSON_THROW_ON_ERROR);
    }
}
