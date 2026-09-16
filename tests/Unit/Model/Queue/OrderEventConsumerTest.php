<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Queue;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\HttpClient\IngestionApiClient;
use AxiTrace\Tracking\Model\Normalizer\OrderEventNormalizer;
use AxiTrace\Tracking\Model\Queue\OrderEventConsumer;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Closes the loop the observer opened: a consent state put into the queue message
 * has to come back out of the consumer as `data.consent` in the ingestion payload.
 */
class OrderEventConsumerTest extends TestCase
{
    public function testConsentFromTheQueueMessageReachesTheIngestionPayload(): void
    {
        $payload = $this->consume(['consent' => 'denied']);

        self::assertSame('denied', $payload['data']['consent']);
    }

    public function testMessageWithoutConsentProducesNoConsentKey(): void
    {
        $payload = $this->consume([]);

        self::assertArrayNotHasKey('consent', $payload['data']);
    }

    /**
     * @param array<string, mixed> $extra Extra keys for the queue message.
     *
     * @return array<string, mixed>
     */
    private function consume(array $extra): array
    {
        $captured = null;

        $client = $this->createMock(IngestionApiClient::class);
        $client->method('sendOrderEvent')->willReturnCallback(
            static function (string $json) use (&$captured): void {
                $captured = $json;
            }
        );

        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(3);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getAllVisibleItems')->willReturn([]);
        $order->method('getGrandTotal')->willReturn(199.99);
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getRemoteIp')->willReturn('203.0.113.7');

        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($order);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getWorkspacePublicKey')->willReturn('pk_test');

        $consumer = new OrderEventConsumer(
            $orders,
            $this->createMock(EventLogRepositoryInterface::class),
            new OrderEventNormalizer(),
            $client,
            $config,
            $this->createMock(LoggerInterface::class),
        );

        $message = array_merge(
            ['order_id' => 42, 'increment_id' => '1000000123', 'event_id_hash' => 'hash-1'],
            $extra
        );

        $consumer->process((string) json_encode($message, JSON_THROW_ON_ERROR));

        self::assertIsString($captured);

        return json_decode((string) $captured, true, 16, JSON_THROW_ON_ERROR);
    }
}
