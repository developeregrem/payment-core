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

        $service = new PaymentService(
            $registry,
            $this->createMock(WebhookHandlerRegistry::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(PaymentTransactionRepository::class),
            $dispatcher,
        );

        $result = $service->syncTransaction($transaction);

        self::assertSame($target, $result);
        self::assertSame($target, $transaction->getStatus());
    }

    public function testSyncTransactionSkipsTerminalStatus(): void
    {
        $transaction = $this->makeTransaction(PaymentStatus::SETTLED);

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

        self::assertSame(PaymentStatus::SETTLED, $service->syncTransaction($transaction));
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
