<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Refund;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\EventId\UuidV5Generator;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use AxiTrace\Tracking\Model\HttpClient\IngestionApiClient;
use AxiTrace\Tracking\Model\Normalizer\OrderLineCostResolver;
use AxiTrace\Tracking\Model\Refund\RefundPayloadBuilder;
use AxiTrace\Tracking\Model\Refund\RefundSender;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A refund reaches `/v1/refund` only with the secret key and only for an order this
 * module reported as a purchase; a failed send is logged critical and never thrown
 * into the merchant's credit memo or cancellation.
 */
class RefundSenderTest extends TestCase
{
    /** @var list<array{json: string, secretKey: string}> */
    private array $sent = [];

    private LoggerInterface&MockObject $logger;

    public function testCreditmemoIsSentWithTheSecretKey(): void
    {
        $this->sender(secretKey: 'sk_test_secret', purchaseStatus: EventLog::STATUS_SENT)
            ->sendCreditmemo($this->creditmemo(), $this->order());

        self::assertCount(1, $this->sent);
        self::assertSame('sk_test_secret', $this->sent[0]['secretKey']);

        $payload = json_decode($this->sent[0]['json'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('42', $payload['orderId']);
        self::assertSame('77', $payload['refundId']);
        self::assertEquals(25.0, $payload['amount']);
        self::assertSame('EUR', $payload['currency']);
        self::assertFalse($payload['isCancellation']);
    }

    public function testCancellationIsSentAsCancellation(): void
    {
        $this->sender(secretKey: 'sk_test_secret', purchaseStatus: EventLog::STATUS_FAILED)
            ->sendCancellation($this->order(totalCanceled: '60.0000'));

        self::assertCount(1, $this->sent);
        $payload = json_decode($this->sent[0]['json'], true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['isCancellation']);
        self::assertSame('cancel-42', $payload['refundId']);
        self::assertEquals(60.0, $payload['amount']);
    }

    public function testNothingIsSentWithoutTheSecretKey(): void
    {
        $this->sender(secretKey: '', purchaseStatus: EventLog::STATUS_SENT)
            ->sendCreditmemo($this->creditmemo(), $this->order());

        self::assertSame([], $this->sent);
    }

    public function testNothingIsSentForAnOrderNeverReportedAsAPurchase(): void
    {
        $this->sender(secretKey: 'sk_test_secret', purchaseStatus: null)
            ->sendCancellation($this->order(totalCanceled: '60.0000'));

        self::assertSame([], $this->sent);
    }

    public function testNothingIsSentForASkippedPurchase(): void
    {
        $this->sender(secretKey: 'sk_test_secret', purchaseStatus: EventLog::STATUS_SKIPPED)
            ->sendCreditmemo($this->creditmemo(), $this->order());

        self::assertSame([], $this->sent);
    }

    public function testNothingIsSentWhenTheModuleIsDisabled(): void
    {
        $this->sender(secretKey: 'sk_test_secret', purchaseStatus: EventLog::STATUS_SENT, enabled: false)
            ->sendCreditmemo($this->creditmemo(), $this->order());

        self::assertSame([], $this->sent);
    }

    public function testFailedSendIsLoggedCriticalAndNotThrown(): void
    {
        $sender = $this->sender(secretKey: 'sk_test_wrong', purchaseStatus: EventLog::STATUS_SENT, failWith: true);
        $this->logger->expects(self::once())->method('critical')
            ->with(self::stringContains('SecretKeyRejectedException'));

        $sender->sendCreditmemo($this->creditmemo(), $this->order());

        self::assertCount(1, $this->sent);
    }

    private function sender(
        string $secretKey,
        ?string $purchaseStatus,
        bool $enabled = true,
        bool $failWith = false,
    ): RefundSender {
        $this->sent = [];

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getSecretKey')->willReturn($secretKey);

        $client = $this->createMock(IngestionApiClient::class);
        $client->method('sendRefund')->willReturnCallback(
            function (string $json, string $key) use ($failWith): void {
                $this->sent[] = ['json' => $json, 'secretKey' => $key];
                if ($failWith) {
                    throw new \AxiTrace\Tracking\Exception\SecretKeyRejectedException('HTTP 401');
                }
            }
        );

        $uuid = new UuidV5Generator();
        $row = null;
        if ($purchaseStatus !== null) {
            $row = $this->createMock(EventLog::class);
            $row->method('getData')->willReturnCallback(
                static fn (?string $key = null) => $key === 'status' ? $purchaseStatus : null
            );
        }
        $eventLog = $this->createMock(EventLogRepositoryInterface::class);
        $eventLog->method('findByEventIdHash')->willReturnCallback(
            static fn (string $hash) => $hash === $uuid->forOrder('1000000123') ? $row : null
        );

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-02 10:15:00');

        $this->logger = $this->createMock(LoggerInterface::class);

        return new RefundSender(
            $config,
            new RefundPayloadBuilder(new OrderLineCostResolver()),
            $client,
            $eventLog,
            $uuid,
            $dateTime,
            $this->logger,
        );
    }

    private function order(?string $totalCanceled = null): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getStoreId')->willReturn(3);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getAllVisibleItems')->willReturn([]);
        $order->method('getTotalCanceled')->willReturn($totalCanceled);

        return $order;
    }

    private function creditmemo(): Creditmemo
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getEntityId')->willReturn(77);
        $creditmemo->method('getGrandTotal')->willReturn('25.0000');
        $creditmemo->method('getCreatedAt')->willReturn('2026-10-01 08:30:00');
        $creditmemo->method('getItems')->willReturn([]);

        return $creditmemo;
    }
}
