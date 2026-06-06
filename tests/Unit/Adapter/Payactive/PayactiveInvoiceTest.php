<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Tests\Unit\Adapter\Payactive;

use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveClient;
use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveProvider;
use Fewohbee\PaymentCore\Dto\BillingAddress;
use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\InvoicePosition;
use Fewohbee\PaymentCore\Enum\ProviderCapability;
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
                self::assertSame('CUSTOMERS_CHOICE', $payload['paymentMethod']);
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

        $provider = new PayactiveProvider($client, ['CUSTOMERS_CHOICE'], 'bank-1');

        $result = $provider->createInvoice($this->request());

        self::assertSame('inv-1', $result->invoiceId);
        self::assertSame('RE-2026-001', $result->invoiceNumber);
        self::assertSame('pay-1', $result->providerPaymentId);
        self::assertSame('https://pay.example/inv-1', $result->redirectUrl);
    }

    public function testCreateInvoiceUpdatesExistingCustomer(): void
    {
        $client = $this->createMock(PayactiveClient::class);
        $client->method('findCustomerIdByEmail')->willReturn('cust-existing');
        $client->expects(self::never())->method('createCustomer');
        $client->expects(self::once())->method('updateCustomer')->with('cust-existing', self::isType('array'));
        $client->method('createInvoice')->willReturn(['id' => 'inv-2']);
        $client->method('finalizeInvoice')->willReturn(['invoiceNumber' => 'RE-2', 'paymentId' => 'pay-2']);
        $client->method('getPaymentLink')->willReturn('https://pay.example/inv-2');

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
        );
    }
}
