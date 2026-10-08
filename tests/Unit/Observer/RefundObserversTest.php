<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Observer;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../magento-stubs.php';

use AxiTrace\Tracking\Model\Refund\RefundSender;
use AxiTrace\Tracking\Observer\CreditmemoRefundObserver;
use AxiTrace\Tracking\Observer\OrderCancelObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wiring of the two refund observers: the right event data reaches RefundSender,
 * a canceled credit memo is ignored, and nothing ever escapes the observer.
 */
class RefundObserversTest extends TestCase
{
    public function testCreditmemoIsHandedToTheSender(): void
    {
        $order = $this->createMock(Order::class);
        $creditmemo = $this->creditmemo(Creditmemo::STATE_REFUNDED, $order);

        $sender = $this->createMock(RefundSender::class);
        $sender->expects(self::once())->method('sendCreditmemo')->with($creditmemo, $order);

        $observer = new CreditmemoRefundObserver($sender, $this->createMock(LoggerInterface::class));
        $observer->execute($this->observerWith('creditmemo', $creditmemo));
        // A second save of the same credit memo in the same request is not resent.
        $observer->execute($this->observerWith('creditmemo', $creditmemo));
    }

    public function testCanceledCreditmemoIsIgnored(): void
    {
        $creditmemo = $this->creditmemo(Creditmemo::STATE_CANCELED, $this->createMock(Order::class));

        $sender = $this->createMock(RefundSender::class);
        $sender->expects(self::never())->method('sendCreditmemo');

        (new CreditmemoRefundObserver($sender, $this->createMock(LoggerInterface::class)))
            ->execute($this->observerWith('creditmemo', $creditmemo));
    }

    public function testCreditmemoObserverNeverThrows(): void
    {
        $creditmemo = $this->creditmemo(Creditmemo::STATE_REFUNDED, $this->createMock(Order::class));

        $sender = $this->createMock(RefundSender::class);
        $sender->method('sendCreditmemo')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')->with(self::stringContains('RuntimeException: boom'));

        (new CreditmemoRefundObserver($sender, $logger))->execute($this->observerWith('creditmemo', $creditmemo));
    }

    public function testCancellationIsHandedToTheSender(): void
    {
        $order = $this->createMock(Order::class);

        $sender = $this->createMock(RefundSender::class);
        $sender->expects(self::once())->method('sendCancellation')->with($order);

        (new OrderCancelObserver($sender, $this->createMock(LoggerInterface::class)))
            ->execute($this->observerWith('order', $order));
    }

    public function testCancelObserverNeverThrows(): void
    {
        $sender = $this->createMock(RefundSender::class);
        $sender->method('sendCancellation')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        (new OrderCancelObserver($sender, $logger))
            ->execute($this->observerWith('order', $this->createMock(Order::class)));
    }

    private function creditmemo(int $state, Order $order): Creditmemo
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getEntityId')->willReturn(77);
        $creditmemo->method('getState')->willReturn($state);
        $creditmemo->method('getOrder')->willReturn($order);

        return $creditmemo;
    }

    private function observerWith(string $key, object $value): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->willReturnCallback(
            static fn (?string $k = null) => $k === $key ? $value : null
        );

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
