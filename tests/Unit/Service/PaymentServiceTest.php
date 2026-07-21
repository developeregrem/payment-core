<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fewohbee\PaymentCore\Dto\PaymentStatusSnapshot;
use Fewohbee\PaymentCore\Entity\PaymentTransaction;
use Fewohbee\PaymentCore\Enum\PaymentIntent;
use Fewohbee\PaymentCore\Enum\PaymentStatus;
use Fewohbee\PaymentCore\Event\PaymentFailedEvent;
use Fewohbee\PaymentCore\Event\PaymentRefundedEvent;
use Fewohbee\PaymentCore\Event\PaymentSettledEvent;
use Fewohbee\PaymentCore\Provider\PaymentProviderInterface;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use Fewohbee\PaymentCore\Provider\PaymentProviderRegistry;
use Fewohbee\PaymentCore\Repository\PaymentTransactionRepository;
use Fewohbee\PaymentCore\Service\PaymentService;
use Fewohbee\PaymentCore\Webhook\WebhookHandlerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class PaymentServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{PaymentStatus, class-string}>
     */
    public static function transitionProvider(): iterable
    {
        yield 'settled' => [PaymentStatus::SETTLED, PaymentSettledEvent::class];
        yield 'failed' => [PaymentStatus::FAILED, PaymentFailedEvent::class];
        yield 'refunded' => [PaymentStatus::REFUNDED, PaymentRefundedEvent::class];
    }

    /**
     * @param class-string $expectedEvent
     */
    #[DataProvider('transitionProvider')]
    public function testSyncTransactionDispatchesEventOnStatusChange(PaymentStatus $target, string $expectedEvent): void
    {
        $transaction = $this->makeTransaction(PaymentStatus::INITIATED);

        $provider = $this->createMock(PaymentProviderInterface::class);
        $provider->method('fetchPaymentStatus')->willReturn(new PaymentStatusSnapshot($target));

        $registry = $this->createMock(PaymentProviderRegistry::class);
        $registry->method('get')->with('payactive')->willReturn($provider);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf($expectedEvent))
            ->willReturnArgument(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback($em));

        $service = new PaymentService(
            $registry,
            $this->createMock(WebhookHandlerRegistry::class),
            $em,
            $this->createMock(PaymentTransactionRepository::class),
            $dispatcher,
        );

        $result = $service->syncTransaction($transaction);

        self::assertSame($target, $result);
        self::assertSame($target, $transaction->getStatus());
    }

    public function testSyncTransactionSkipsFinalReversalStatus(): void
    {
        $transaction = $this->makeTransaction(PaymentStatus::CHARGED_BACK);

        $registry = $this->createMock(PaymentProviderRegistry::class);
        $registry->expects(self::never())->method('get');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $service = new PaymentService(
            $registry,
            $this->createMock(WebhookHandlerRegistry::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(PaymentTransactionRepository::class),
            $dispatcher,
        );

        self::assertSame(PaymentStatus::CHARGED_BACK, $service->syncTransaction($transaction));
    }

    public function testProviderFailureIsPersistedAndRescheduled(): void
    {
        $transaction = $this->makeTransaction(PaymentStatus::INITIATED);
        $provider = $this->createMock(PaymentProviderInterface::class);
        $provider->method('fetchPaymentStatus')->willThrowException(new PaymentProviderException('temporarily unavailable'));
        $registry = $this->createMock(PaymentProviderRegistry::class);
        $registry->method('get')->willReturn($provider);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new PaymentService(
            $registry,
            $this->createMock(WebhookHandlerRegistry::class),
            $em,
            $this->createMock(PaymentTransactionRepository::class),
            $this->createMock(EventDispatcherInterface::class),
        );

        $result = $service->syncTransactionResult($transaction);

        self::assertFalse($result->successful);
        self::assertSame(1, $transaction->getCheckFailureCount());
        self::assertSame('temporarily unavailable', $transaction->getLastCheckError());
        self::assertGreaterThan(new \DateTimeImmutable(), $transaction->getNextCheckAt());
    }

    public function testConcurrentSettlementCannotBeOverwrittenByStaleFailure(): void
    {
        $transaction = $this->makeTransaction(PaymentStatus::INITIATED);
        (new \ReflectionProperty(PaymentTransaction::class, 'id'))->setValue($transaction, 42);

        $provider = $this->createStub(PaymentProviderInterface::class);
        $provider->method('fetchPaymentStatus')->willReturn(new PaymentStatusSnapshot(PaymentStatus::FAILED));
        $registry = $this->createStub(PaymentProviderRegistry::class);
        $registry->method('get')->willReturn($provider);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback($em));
        $em->expects(self::once())->method('lock');
        $em->expects(self::once())->method('refresh')->willReturnCallback(
            static function (PaymentTransaction $reloaded): void {
                // Simulates a settlement committed by another worker while this
                // worker waited for the pessimistic row lock.
                $reloaded->setStatus(PaymentStatus::SETTLED);
            },
        );

        $service = new PaymentService(
            $registry,
            $this->createStub(WebhookHandlerRegistry::class),
            $em,
            $this->createStub(PaymentTransactionRepository::class),
            $dispatcher,
        );

        self::assertSame(PaymentStatus::SETTLED, $service->syncTransaction($transaction));
        self::assertSame(PaymentStatus::SETTLED, $transaction->getStatus());
        self::assertGreaterThan(new \DateTimeImmutable('+23 hours'), $transaction->getNextCheckAt());
    }

    private function makeTransaction(PaymentStatus $status): PaymentTransaction
    {
        return new PaymentTransaction(
            providerId: 'payactive',
            providerPaymentId: 'pay_123',
            externalReference: 'order_1',
            amount: 47.6,
            currency: 'EUR',
            purpose: 'Test',
            intent: PaymentIntent::PAYMENT,
            status: $status,
        );
    }
}
