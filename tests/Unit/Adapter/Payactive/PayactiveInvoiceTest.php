<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Tests\Unit\Adapter\Payactive;

use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveClient;
use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveProvider;
use Fewohbee\PaymentCore\Dto\BillingAddress;
use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\InvoicePosition;
use Fewohbee\PaymentCore\Enum\ProviderCapability;
use Fewohbee\PaymentCore\Enum\CollectionMode;
use Fewohbee\PaymentCore\Enum\PaymentMethodChangeDelivery;
use Fewohbee\PaymentCore\Enum\PaymentStatus;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use PHPUnit\Framework\TestCase;

final class PayactiveInvoiceTest extends TestCase
{
    public function testSupportsInvoiceOnlyWhenCreditorConfigured(): void
    {
        $client = $this->createMock(PayactiveClient::class);

        $withCreditor = new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1');
        self::assertTrue($withCreditor->supports(ProviderCapability::INVOICE));

        $without = new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], null);
        self::assertFalse($without->supports(ProviderCapability::INVOICE));
    }

    public function testCreateInvoiceMapsPositionsTaxAndCustomer(): void
    {
        $client = $this->createMock(PayactiveClient::class);

        // New customer (none found) → createCustomer with address + vatId + type.
        $client->method('findCustomerIdByEmail')->willReturn(null);
        $client->expects(self::once())
            ->method('createCustomer')
            ->with(self::callback(function (array $payload): bool {
                self::assertSame('ORGANIZATION', $payload['type']);
                self::assertSame('DE123456789', $payload['vatId']);
                self::assertSame('Acme GmbH', $payload['companyName']);
                // Customer always gets a concrete method (CUSTOMERS_CHOICE is invalid here).
                self::assertSame('ONLINE_PAYMENT', $payload['paymentMethod']);
                self::assertSame('customer-7', $payload['externalRef']);
                self::assertSame('Hauptstr. 1', $payload['address']['line']);
                self::assertSame('01099', $payload['address']['zipCode']);

                return true;
            }))
            ->willReturn('cust-1');

        $client->expects(self::once())
            ->method('createInvoice')
            ->with(self::callback(function (array $payload): bool {
                self::assertSame('cust-1', $payload['customerId']);
                self::assertSame('bank-1', $payload['creditorBankAccountId']);
                self::assertTrue($payload['grossInvoice']);
                self::assertCount(2, $payload['positions']);
                self::assertSame('Plan small (jährlich)', $payload['positions'][0]['description']);
                self::assertSame(319.0, $payload['positions'][0]['price']);
                self::assertSame(19.0, $payload['positions'][0]['taxRate']['rate']);
                self::assertSame(7, $payload['paymentTermInDays']);
                self::assertSame([
                    ['key' => 'externalReference', 'value' => 'order-42', 'publicVisible' => false],
                    ['key' => 'orderReference', 'value' => 'order-42', 'publicVisible' => false],
                ], $payload['metadata']);

                return true;
            }))
            ->willReturn(['id' => 'inv-1']);

        $client->expects(self::once())
            ->method('finalizeInvoice')
            ->with('inv-1')
            ->willReturn(['invoiceNumber' => 'RE-2026-001', 'paymentId' => 'pay-1']);

        $client->expects(self::once())
            ->method('getPaymentLink')
            ->with('pay-1')
            ->willReturn('https://pay.example/inv-1');
        $client->method('getPayment')->with('pay-1')->willReturn(['paymentMethod' => 'ONLINE_PAYMENT']);

        $provider = new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1');

        $result = $provider->createInvoice($this->request());

        self::assertSame('inv-1', $result->invoiceId);
        self::assertSame('RE-2026-001', $result->invoiceNumber);
        self::assertSame('pay-1', $result->providerPaymentId);
        self::assertSame('https://pay.example/inv-1', $result->redirectUrl);
        self::assertSame('cust-1', $result->providerCustomerId);
        self::assertSame(CollectionMode::HOSTED_ACTION, $result->collectionMode);
    }

    public function testCreateInvoiceUpdatesExistingCustomer(): void
    {
        $client = $this->createMock(PayactiveClient::class);
        $client->method('findCustomerByEmail')->willReturn([
            'id' => 'cust-existing',
            'emailAddress' => 'kunde@example.com',
            'paymentMethod' => 'MANUAL_PAYMENT',
        ]);
        $client->expects(self::never())->method('createCustomer');
        $client->expects(self::once())
            ->method('updateCustomer')
            ->with('cust-existing', self::callback(static function (array $payload): bool {
                self::assertSame('MANUAL_PAYMENT', $payload['paymentMethod']);

                return true;
            }));
        $client->method('createInvoice')->willReturn(['id' => 'inv-2']);
        $client->method('finalizeInvoice')->willReturn(['invoiceNumber' => 'RE-2', 'paymentId' => 'pay-2']);
        $client->method('getPaymentLink')->willReturn('https://pay.example/inv-2');
        $client->method('getPayment')->willReturn(['paymentMethod' => 'MANUAL_PAYMENT']);

        $provider = new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1');
        $result = $provider->createInvoice($this->request());

        self::assertSame('inv-2', $result->invoiceId);
    }

    public function testCreateInvoiceThrowsWithoutCreditor(): void
    {
        $provider = new PayactiveProvider($this->createMock(PayactiveClient::class), ['CUSTOMERS_CHOICE'], null);

        $this->expectException(PaymentProviderException::class);
        $provider->createInvoice($this->request());
    }

    public function testDownloadInvoiceWrapsBinary(): void
    {
        $client = $this->createMock(PayactiveClient::class);
        $client->method('exportInvoice')->with('inv-9')->willReturn([
            'content' => '%PDF-1.7 bytes',
            'contentType' => 'application/pdf',
        ]);

        $provider = new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1');
        $doc = $provider->downloadInvoice('inv-9');

        self::assertSame('application/pdf', $doc->contentType);
        self::assertSame('%PDF-1.7 bytes', $doc->content);
        self::assertStringContainsString('inv-9', $doc->filename);
    }

    public function testSendPaymentReminderDelegatesToClient(): void
    {
        $client = $this->createMock(PayactiveClient::class);
        $client->expects(self::once())->method('sendPaymentReminder')->with('pay-1');

        $provider = new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1');
        $provider->sendPaymentReminder('pay-1');
    }

    public function testInvoiceWithoutPayLinkCanBeAutomaticCollection(): void
    {
        $client = $this->createMock(PayactiveClient::class);
        $client->method('findCustomerByEmail')->willReturn(null);
        $client->method('createCustomer')->willReturn('cust-1');
        $client->method('createInvoice')->willReturn(['id' => 'inv-1']);
        $client->method('finalizeInvoice')->willReturn(['invoiceNumber' => 'RE-1', 'paymentId' => 'pay-1']);
        $client->method('getPaymentLink')->willReturn(null);
        $client->method('getPayment')->willReturn(['paymentMethod' => 'DIRECT_DEBIT']);

        $result = (new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1'))->createInvoice($this->request());

        self::assertNull($result->redirectUrl);
        self::assertSame(CollectionMode::AUTOMATIC, $result->collectionMode);
        self::assertSame('DIRECT_DEBIT', $result->providerPaymentMethod);
    }

    public function testRequestsProviderManagedPaymentMethodChangeByEmail(): void
    {
        $client = $this->createMock(PayactiveClient::class);
        $client->expects(self::once())->method('requestPaymentMethodChange')->with('cust-1', 'EMAIL')->willReturn(null);
        $provider = new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1');

        $result = $provider->requestPaymentMethodChange('cust-1', PaymentMethodChangeDelivery::EMAIL);

        self::assertSame(PaymentMethodChangeDelivery::EMAIL, $result->delivery);
        self::assertNull($result->actionUrl);
    }

    public function testRecoversExistingInvoiceByMetadataWithoutDuplicatePost(): void
    {
        $client = $this->createMock(PayactiveClient::class);
        $client->method('findCustomerByEmail')->willReturn([
            'id' => 'cust-1',
            'paymentMethod' => 'DIRECT_DEBIT',
        ]);
        $client->method('findInvoiceByMetadata')->with('externalReference', 'order-42')->willReturn([
            'id' => 'inv-existing',
            'status' => 'OPEN',
            'invoiceNumber' => 'RE-existing',
            'paymentId' => 'pay-existing',
        ]);
        $client->expects(self::never())->method('createInvoice');
        $client->expects(self::never())->method('finalizeInvoice');
        $client->method('getPaymentLink')->willReturn(null);
        $client->method('getPayment')->willReturn(['paymentMethod' => 'DIRECT_DEBIT']);

        $result = (new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1'))->createInvoice($this->request());

        self::assertSame('inv-existing', $result->invoiceId);
        self::assertSame(CollectionMode::AUTOMATIC, $result->collectionMode);
    }

    public function testPollingDistinguishesRefundAndChargebackReviewStates(): void
    {
        $client = $this->createMock(PayactiveClient::class);
        $client->method('getPayment')->willReturnOnConsecutiveCalls(
            ['state' => 'REFUND_IN_PROGRESS'],
            ['state' => 'REFUND_COMPLETED'],
            ['state' => 'CHARGED_BACK'],
        );
        $provider = new PayactiveProvider($client);

        self::assertSame(PaymentStatus::REFUND_PENDING, $provider->fetchPaymentStatus('p')->status);
        self::assertSame(PaymentStatus::REFUNDED, $provider->fetchPaymentStatus('p')->status);
        self::assertSame(PaymentStatus::CHARGED_BACK, $provider->fetchPaymentStatus('p')->status);
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
                new InvoicePosition('Plan small (jährlich)', 1, 319.0, 19.0),
                new InvoicePosition('Onboarding', 1, 49.0, 19.0),
            ],
            companyName: 'Acme GmbH',
            vatId: 'DE123456789',
            customerType: 'ORGANIZATION',
            grossInvoice: true,
            paymentTermInDays: 7,
            customerExternalReference: 'customer-7',
            metadata: ['orderReference' => 'order-42'],
        );
    }
}
