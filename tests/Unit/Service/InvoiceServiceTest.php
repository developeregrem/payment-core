<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Fewohbee\PaymentCore\Dto\BillingAddress;
use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\CreatePaymentRequest;
use Fewohbee\PaymentCore\Dto\InvoiceDocument;
use Fewohbee\PaymentCore\Dto\InvoiceInitiation;
use Fewohbee\PaymentCore\Dto\PaymentInitiation;
use Fewohbee\PaymentCore\Dto\PaymentStatusSnapshot;
use Fewohbee\PaymentCore\Entity\PaymentTransaction;
use Fewohbee\PaymentCore\Enum\PaymentIntent;
use Fewohbee\PaymentCore\Enum\PaymentStatus;
use Fewohbee\PaymentCore\Enum\ProviderCapability;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use Fewohbee\PaymentCore\Provider\InvoiceProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentProviderRegistry;
use Fewohbee\PaymentCore\Provider\PaymentReminderProviderInterface;
use Fewohbee\PaymentCore\Service\InvoiceService;
use PHPUnit\Framework\TestCase;

final class InvoiceServiceTest extends TestCase
{
    public function testCreateInvoicePersistsTransactionWithInvoiceMetadata(): void
    {
        $provider = $this->invoiceProvider(
            new InvoiceInitiation('inv-1', 'RE-1', 'pay-1', 'https://pay/inv-1')
        );
        $registry = new PaymentProviderRegistry([$provider], 'payactive');

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')
            ->with(self::callback(function (PaymentTransaction $t) use (&$persisted): bool {
                $persisted = $t;

                return true;
            }));
        $em->expects(self::once())->method('flush');

        $service = new InvoiceService($registry, $em);
        $result = $service->createInvoice($this->request());

        self::assertSame('inv-1', $result->invoiceId);
        self::assertInstanceOf(PaymentTransaction::class, $persisted);
        self::assertSame('pay-1', $persisted->getProviderPaymentId());
        self::assertSame(368.0, $persisted->getAmount()); // 319 + 49
        self::assertSame(PaymentStatus::PENDING, $persisted->getStatus());
        self::assertSame('inv-1', $persisted->getMetadata()['invoiceId'] ?? null);
        self::assertSame('RE-1', $persisted->getMetadata()['invoiceNumber'] ?? null);
    }

    public function testCreateInvoiceThrowsWhenProviderCannotInvoice(): void
    {
        // A pure payment provider (no InvoiceProviderInterface).
        $paymentOnly = new class implements PaymentProviderInterface {
            public function getId(): string
            {
                return 'payonly';
            }

            public function supports(ProviderCapability $c): bool
            {
                return false;
            }

            public function createPayment(CreatePaymentRequest $r): PaymentInitiation
            {
                return new PaymentInitiation('x', null);
            }

            public function fetchPaymentStatus(string $id): PaymentStatusSnapshot
            {
                return new PaymentStatusSnapshot(PaymentStatus::PENDING);
            }
        };
        $registry = new PaymentProviderRegistry([$paymentOnly], 'payonly');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $service = new InvoiceService($registry, $em);

        $this->expectException(PaymentProviderException::class);
        $service->createInvoice($this->request());
    }

    public function testSendPaymentReminderUsesInvoiceTransactionProviderPaymentId(): void
    {
        $provider = new class implements PaymentProviderInterface, PaymentReminderProviderInterface {
            public ?string $remindedPaymentId = null;

            public function getId(): string
            {
                return 'payactive';
            }

            public function supports(ProviderCapability $c): bool
            {
                return false;
            }

            public function createPayment(CreatePaymentRequest $r): PaymentInitiation
            {
                return new PaymentInitiation('x', null);
            }

            public function fetchPaymentStatus(string $id): PaymentStatusSnapshot
            {
                return new PaymentStatusSnapshot(PaymentStatus::PENDING);
            }

            public function sendPaymentReminder(string $providerPaymentId): void
            {
                $this->remindedPaymentId = $providerPaymentId;
            }
        };

        $transaction = new PaymentTransaction(
            providerId: 'payactive',
            providerPaymentId: 'pay-1',
            externalReference: 'order-42',
            amount: 368.0,
            currency: 'EUR',
            purpose: 'FewohBee Cloud',
            intent: PaymentIntent::PAYMENT,
        );
        $transaction->setMetadata(['invoiceId' => 'inv-1']);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['externalReference' => 'order-42'], ['createdAt' => 'DESC'])
            ->willReturn([$transaction]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(PaymentTransaction::class)->willReturn($repository);

        $service = new InvoiceService(new PaymentProviderRegistry([$provider], 'payactive'), $em);
        $service->sendPaymentReminder('order-42', 'inv-1');

        self::assertSame('pay-1', $provider->remindedPaymentId);
    }

    private function invoiceProvider(InvoiceInitiation $initiation): PaymentProviderInterface&InvoiceProviderInterface
    {
        return new class($initiation) implements PaymentProviderInterface, InvoiceProviderInterface {
            public function __construct(private readonly InvoiceInitiation $initiation)
            {
            }

            public function getId(): string
            {
                return 'payactive';
            }

            public function supports(ProviderCapability $c): bool
            {
                return ProviderCapability::INVOICE === $c;
            }

            public function createPayment(CreatePaymentRequest $r): PaymentInitiation
            {
                return new PaymentInitiation('x', null);
            }

            public function fetchPaymentStatus(string $id): PaymentStatusSnapshot
            {
                return new PaymentStatusSnapshot(PaymentStatus::PENDING);
            }

            public function createInvoice(CreateInvoiceRequest $r): InvoiceInitiation
            {
                return $this->initiation;
            }

            public function downloadInvoice(string $invoiceId): InvoiceDocument
            {
                return new InvoiceDocument('f.pdf', 'application/pdf', 'x');
            }
        };
    }

    private function request(): CreateInvoiceRequest
    {
        return new CreateInvoiceRequest(
            externalReference: 'order-42',
            currency: 'EUR',
            purpose: 'FewohBee Cloud',
            customerEmail: 'kunde@example.com',
            customerFirstName: 'Erika',
            customerLastName: 'Muster',
            address: new BillingAddress('Hauptstr. 1', '01099', 'Dresden'),
            positions: [
                new \Fewohbee\PaymentCore\Dto\InvoicePosition('Plan', 1, 319.0, 19.0),
                new \Fewohbee\PaymentCore\Dto\InvoicePosition('Onboarding', 1, 49.0, 19.0),
            ],
        );
    }
}
