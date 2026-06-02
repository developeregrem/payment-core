<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Tests\Unit\Adapter\Payactive;

use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveWebhookHandler;
use Fewohbee\PaymentCore\Enum\WebhookEventType;
use Fewohbee\PaymentCore\Exception\WebhookSignatureException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PayactiveWebhookHandlerTest extends TestCase
{
    private const SECRET = 'test_signing_secret';
    private const FIXTURE_DIR = __DIR__.'/../../../Fixtures';

    public function testValidSignatureReturnsNormalizedSettledEvent(): void
    {
        $body = (string) file_get_contents(self::FIXTURE_DIR.'/payactive-webhook-settled.json');

        $event = (new PayactiveWebhookHandler(self::SECRET))
            ->handle($this->makeRequest($body, $this->sign($body)));

        self::assertNotNull($event);
        self::assertSame(WebhookEventType::SETTLED, $event->type);
        self::assertSame('d6d8d863-d46c-4044-9ff3-68cd67142abd', $event->providerPaymentId);
        self::assertSame(47.6, $event->amount);
    }

    public function testPaymentFailedIsMapped(): void
    {
        $body = (string) file_get_contents(self::FIXTURE_DIR.'/payactive-webhook-failed.json');

        $event = (new PayactiveWebhookHandler(self::SECRET))
            ->handle($this->makeRequest($body, $this->sign($body)));

        self::assertNotNull($event);
        self::assertSame(WebhookEventType::FAILED, $event->type);
        self::assertSame('d6d8d863-d46c-4044-9ff3-68cd67142abd', $event->providerPaymentId);
    }

    public function testTamperedBodyIsRejected(): void
    {
        $body = (string) file_get_contents(self::FIXTURE_DIR.'/payactive-webhook-settled.json');
        $signature = $this->sign($body);
        $tampered = str_replace('47.6', '99.9', $body);

        $this->expectException(WebhookSignatureException::class);
        (new PayactiveWebhookHandler(self::SECRET))->handle($this->makeRequest($tampered, $signature));
    }

    public function testMissingSignatureHeaderIsRejected(): void
    {
        $this->expectException(WebhookSignatureException::class);
        (new PayactiveWebhookHandler(self::SECRET))->handle($this->makeRequest('{}', null));
    }

    public function testMissingSecretIsRejected(): void
    {
        $this->expectException(WebhookSignatureException::class);
        (new PayactiveWebhookHandler(''))->handle($this->makeRequest('{}', 'doesnt-matter'));
    }

    public function testUnknownEventTypeReturnsNull(): void
    {
        $body = (string) json_encode([
            'event_type' => 'checkout.completed',
            'event_data' => ['payment_id' => 'abc'],
        ]);

        $handler = new PayactiveWebhookHandler(self::SECRET);

        self::assertNull($handler->handle($this->makeRequest($body, $this->sign($body))));
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::SECRET);
    }

    private function makeRequest(string $body, ?string $signature): Request
    {
        $server = [];
        if (null !== $signature) {
            $server['HTTP_X_PAYLOAD_SIGNATURE'] = $signature;
        }

        return new Request([], [], [], [], [], $server, $body);
    }
}
