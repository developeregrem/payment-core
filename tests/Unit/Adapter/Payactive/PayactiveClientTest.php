<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Tests\Unit\Adapter\Payactive;

use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveClient;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class PayactiveClientTest extends TestCase
{
    public function testExportInvoiceDecodesJsonBase64PdfWrapper(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $response->method('getContent')->willReturn(json_encode([
            'content' => base64_encode("%PDF-1.7\nbody"),
        ], JSON_THROW_ON_ERROR));

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())
            ->method('request')
            ->with('GET', 'https://pay.example/invoices/inv-1/actions/export/pdf', self::callback(static function (mixed $options): bool {
                self::assertIsArray($options);

                return true;
            }))
            ->willReturn($response);

        $client = new PayactiveClient($http, 'api-key', 'https://pay.example');
        $document = $client->exportInvoice('inv-1');

        self::assertSame("%PDF-1.7\nbody", $document['content']);
        self::assertSame('application/pdf', $document['contentType']);
    }

    public function testMissingPaymentFlowLinkReturnsNull(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getContent')->willReturn('{ "status" : "NOT_FOUND", "message" : "Payment Flow link could not be found." }');

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturn($response);

        $client = new PayactiveClient($http, 'api-key', 'https://pay.example');

        self::assertNull($client->getPaymentLink('pay-1'));
    }

    public function testPaymentLinkHttpErrorsStillThrow(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->willReturn('server error');

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturn($response);

        $client = new PayactiveClient($http, 'api-key', 'https://pay.example');

        $this->expectException(PaymentProviderException::class);
        $client->getPaymentLink('pay-1');
    }
}
