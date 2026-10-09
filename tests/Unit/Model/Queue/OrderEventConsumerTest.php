<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Queue;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Exception\SecretKeyRejectedException;
use AxiTrace\Tracking\Model\Config\ModuleConfig;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use AxiTrace\Tracking\Model\HttpClient\IngestionApiClient;
use AxiTrace\Tracking\Model\Identity\OrderBrowserIdentityStore;
use AxiTrace\Tracking\Model\Normalizer\OrderEventNormalizer;
use AxiTrace\Tracking\Model\Normalizer\OrderLineCostResolver;
use AxiTrace\Tracking\Model\Queue\OrderEventConsumer;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Closes the loop the observer opened: a consent state put into the queue message
 * has to come back out of the consumer as `data.consent` in the ingestion payload.
 * Also covers the secret key: it authenticates the request and unlocks unit costs,
 * and a rejected key never costs the merchant the purchase itself.
 */
class OrderEventConsumerTest extends TestCase
{
    /** @var list<array{json: string, secretKey: string}> */
    private array $sent = [];

    private ?string $savedNote = null;

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

    public function testIdentityStoredOnTheOrderReachesTheIngestionPayload(): void
    {
        // The retry cron re-publishes without any identity in the message: the one
        // stored on the order at placement time must still be sent.
        $payload = $this->consume([], storedIdentity: (string) json_encode([
            'visitor_id' => '6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10',
            'session_id' => '0b9a7c65-1d2e-4f30-8a9b-c1d2e3f4a5b6',
            'ip'         => '198.51.100.23',
            'user_agent' => 'Mozilla/5.0 Test',
            'gclid'      => 'Cj0KCQjw-gclid',
            'rdt_cid'    => 'rdt-click-1',
        ]));

        self::assertSame('6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10', $payload['userId']);
        self::assertSame('0b9a7c65-1d2e-4f30-8a9b-c1d2e3f4a5b6', $payload['sessionId']);
        self::assertSame('198.51.100.23', $payload['ip']);
        self::assertSame('Mozilla/5.0 Test', $payload['userAgent']);
        self::assertSame('Cj0KCQjw-gclid', $payload['data']['gclid']);
        self::assertSame('rdt-click-1', $payload['data']['rdt_cid']);
    }

    public function testIdentityInTheMessageWinsOverTheStoredOne(): void
    {
        $payload = $this->consume(
            ['browser' => ['visitor_id' => 'message-visitor-0001', 'ttclid' => 'E.C.P.ttclid']],
            storedIdentity: '{"visitor_id":"stored-visitor-00001","gclid":"stored-gclid"}'
        );

        self::assertSame('message-visitor-0001', $payload['userId']);
        self::assertSame('E.C.P.ttclid', $payload['data']['ttclid']);
        self::assertSame('stored-gclid', $payload['data']['gclid']);
    }

    public function testMessageQueuedByAnOlderModuleStillForwardsItsMetaCookies(): void
    {
        $payload = $this->consume(['fbp' => 'fb.1.1700000000000.1234567890', 'fbc' => 'fb.1.1700000000000.IwAR']);

        self::assertSame('fb.1.1700000000000.1234567890', $payload['data']['fbp']);
        self::assertSame('fb.1.1700000000000.IwAR', $payload['data']['fbc']);
        self::assertArrayNotHasKey('userId', $payload);
    }

    public function testUnreadableStoredIdentityStillSendsThePurchase(): void
    {
        $payload = $this->consume([], storedIdentity: '{not json');

        self::assertArrayNotHasKey('userId', $payload);
        self::assertSame('203.0.113.7', $payload['ip']);
    }

    public function testWithoutSecretKeyNoCostIsSentAndNoKeyIsPassed(): void
    {
        $payload = $this->consume([], '');

        self::assertCount(1, $this->sent);
        self::assertSame('', $this->sent[0]['secretKey']);
        self::assertArrayNotHasKey('unitCost', $payload['data']['products'][0]);
    }

    public function testSecretKeyAuthenticatesTheRequestAndAddsUnitCost(): void
    {
        $payload = $this->consume([], 'sk_test_secret');

        self::assertCount(1, $this->sent);
        self::assertSame('sk_test_secret', $this->sent[0]['secretKey']);
        self::assertSame(['amount' => 12.5, 'currency' => 'EUR'], $payload['data']['products'][0]['unitCost']);
    }

    public function testRejectedSecretKeyResendsThePurchaseWithoutKeyAndWithoutCosts(): void
    {
        $payload = $this->consume([], 'sk_test_wrong', rejectSecretKey: true);

        self::assertCount(2, $this->sent);
        self::assertSame('sk_test_wrong', $this->sent[0]['secretKey']);
        self::assertSame('', $this->sent[1]['secretKey']);
        self::assertArrayNotHasKey('unitCost', $payload['data']['products'][0]);
        self::assertSame('magento:501', $payload['data']['products'][0]['externalId']);
    }

    public function testAcceptedKeyLeavesNoNoteOnTheEventLog(): void
    {
        $this->consume([], 'sk_test_secret');

        self::assertNull($this->savedNote);
    }

    public function testUnrecognisedKeyIsNotedOnTheEventLogForTheMerchant(): void
    {
        $this->consume([], 'sk_test_garbage', unverifiedKey: true);

        self::assertCount(1, $this->sent);
        self::assertSame(OrderEventConsumer::NOTE_KEY_UNVERIFIED, $this->savedNote);
    }

    public function testRejectedKeyIsNotedOnTheEventLogForTheMerchant(): void
    {
        $this->consume([], 'sk_test_wrong', rejectSecretKey: true);

        self::assertSame(OrderEventConsumer::NOTE_KEY_REJECTED, $this->savedNote);
    }

    /**
     * @param array<string, mixed> $extra Extra keys for the queue message.
     *
     * @return array<string, mixed> The last payload sent.
     */
    private function consume(
        array $extra,
        string $secretKey = '',
        bool $rejectSecretKey = false,
        ?string $storedIdentity = null,
        bool $unverifiedKey = false,
    ): array {
        $this->sent = [];
        $this->savedNote = 'not saved';

        // A real row (its setters and getData) without the model's framework constructor.
        $row = $this->createPartialMock(EventLog::class, []);
        $eventLog = $this->createMock(EventLogRepositoryInterface::class);
        $eventLog->method('findByEventIdHash')->willReturn($row);
        $eventLog->method('save')->willReturnCallback(function (EventLog $saved): EventLog {
            $this->savedNote = $saved->getData('last_error');

            return $saved;
        });

        $client = $this->createMock(IngestionApiClient::class);
        $client->method('sendOrderEvent')->willReturnCallback(
            function (string $json, string $key = '') use ($rejectSecretKey, $unverifiedKey): bool {
                $this->sent[] = ['json' => $json, 'secretKey' => $key];
                if ($rejectSecretKey && $key !== '') {
                    throw new SecretKeyRejectedException('HTTP 401');
                }

                return !($unverifiedKey && $key !== '');
            }
        );

        $item = $this->createMock(OrderItem::class);
        $item->method('getProductId')->willReturn(501);
        $item->method('getSku')->willReturn('SKU-1');
        $item->method('getQtyOrdered')->willReturn(1);
        $item->method('getPrice')->willReturn(40.0);
        $item->method('getBaseCost')->willReturn('12.5000');

        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(3);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getBaseCurrencyCode')->willReturn('EUR');
        $order->method('getAllVisibleItems')->willReturn([$item]);
        $order->method('getGrandTotal')->willReturn(199.99);
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('1000000123');
        $order->method('getRemoteIp')->willReturn('203.0.113.7');
        $order->method('getData')->willReturnCallback(
            static fn ($key = null) => $key === OrderBrowserIdentityStore::COLUMN ? $storedIdentity : null
        );

        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($order);

        $config = $this->createMock(ModuleConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getWorkspacePublicKey')->willReturn('pk_test');
        $config->method('getSecretKey')->willReturn($secretKey);

        $consumer = new OrderEventConsumer(
            $orders,
            $eventLog,
            new OrderEventNormalizer(new OrderLineCostResolver()),
            $client,
            $config,
            new OrderBrowserIdentityStore($this->createMock(LoggerInterface::class)),
            $this->createMock(LoggerInterface::class),
        );

        $message = array_merge(
            ['order_id' => 42, 'increment_id' => '1000000123', 'event_id_hash' => 'hash-1'],
            $extra
        );

        $consumer->process((string) json_encode($message, JSON_THROW_ON_ERROR));

        self::assertNotEmpty($this->sent);

        return json_decode($this->sent[array_key_last($this->sent)]['json'], true, 16, JSON_THROW_ON_ERROR);
    }
}
