<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Adapter\Payactive;

use Fewohbee\PaymentCore\Dto\NormalizedWebhookEvent;
use Fewohbee\PaymentCore\Enum\WebhookEventType;
use Fewohbee\PaymentCore\Exception\WebhookSignatureException;
use Fewohbee\PaymentCore\Webhook\WebhookHandlerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payactive webhook handler. Verifies the HMAC-SHA256 signature on `x-payload-signature`
 * and normalizes the payload.
 *
 * Payactive delivers two different envelope shapes depending on the webhook
 * configuration (both seen in production, same event names, no announced
 * cutover date):
 *   - legacy: {"event_type": "payment.settled", "event_data": {"payment_id": ..., "amount": ...}}
 *   - CloudEvents (current default): {"type": "payments.payment.settled",
 *     "specversion": "1.0", "data": {"paymentId": ..., "amount": ..., "status": "SETTLED", ...}}
 * Both are handled so a per-tenant webhook config on either shape keeps working.
 */
class PayactiveWebhookHandler implements WebhookHandlerInterface
{
    public function __construct(
        private readonly string $signingSecret,
    ) {
    }

    public function getProviderId(): string
    {
        return PayactiveProvider::ID;
    }

    public function handle(Request $request): ?NormalizedWebhookEvent
    {
        $rawBody = $request->getContent();
        $headerSignature = (string) $request->headers->get('x-payload-signature', '');

        if ('' === $this->signingSecret) {
            throw new WebhookSignatureException('Payactive: PAYACTIVE_WEBHOOK_SECRET is not configured.');
        }
        if ('' === $headerSignature) {
            throw new WebhookSignatureException('Payactive: missing x-payload-signature header.');
        }

        $expected = hash_hmac('sha256', $rawBody, $this->signingSecret);
        if (!hash_equals($expected, $headerSignature)) {
            throw new WebhookSignatureException('Payactive: invalid webhook signature.');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new WebhookSignatureException('Payactive: webhook body is not valid JSON.');
        }

        if (isset($payload['specversion'], $payload['type'], $payload['data'])) {
            return $this->handleCloudEvent($payload);
        }

        return $this->handleLegacyEnvelope($payload);
    }

    /** @param array<string, mixed> $payload */
    private function handleCloudEvent(array $payload): ?NormalizedWebhookEvent
    {
        $data = $payload['data'];
        if (!is_array($data)) {
            return null;
        }

        // "data.status" is preferred: it names the outcome directly (confirmed
        // values "SETTLED" and "INITIATED"). The envelope's "type" field is
        // used as a fallback, same pattern ("payments.payment.<name>"). FAILED
        // is inferred from that pattern but not yet confirmed against a live
        // payload — re-verify once such a webhook has actually been observed.
        $status = isset($data['status']) && is_string($data['status']) ? strtoupper($data['status']) : '';
        $type = match ($status) {
            'INITIATED' => WebhookEventType::INITIATED,
            'SETTLED' => WebhookEventType::SETTLED,
            'FAILED' => WebhookEventType::FAILED,
            default => null,
        };

        if (null === $type) {
            $eventType = isset($payload['type']) && is_string($payload['type']) ? $payload['type'] : '';
            $type = match ($eventType) {
                'payments.payment.initiated' => WebhookEventType::INITIATED,
                'payments.payment.settled' => WebhookEventType::SETTLED,
                'payments.payment.failed' => WebhookEventType::FAILED,
                default => null,
            };
        }

        if (null === $type) {
            return null;
        }

        $providerPaymentId = isset($data['paymentId']) && is_string($data['paymentId']) ? $data['paymentId'] : '';
        if ('' === $providerPaymentId) {
            return null;
        }

        $amount = isset($data['amount']) && (is_int($data['amount']) || is_float($data['amount']))
            ? (float) $data['amount']
            : null;

        return new NormalizedWebhookEvent(
            type: $type,
            providerPaymentId: $providerPaymentId,
            amount: $amount,
            raw: $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    private function handleLegacyEnvelope(array $payload): ?NormalizedWebhookEvent
    {
        $eventType = isset($payload['event_type']) && is_string($payload['event_type']) ? $payload['event_type'] : '';
        $type = match ($eventType) {
            'payment.initiated' => WebhookEventType::INITIATED,
            'payment.settled' => WebhookEventType::SETTLED,
            // payment.failed is offered in the Payactive portal UI even though it
            // is missing from the public docs (confirmed by the Payactive team).
            'payment.failed' => WebhookEventType::FAILED,
            // Unknown / checkout.* events are ignored (caller responds 200).
            default => null,
        };

        if (null === $type) {
            return null;
        }

        $data = $payload['event_data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        $providerPaymentId = isset($data['payment_id']) && is_string($data['payment_id']) ? $data['payment_id'] : '';
        if ('' === $providerPaymentId) {
            return null;
        }

        $amount = isset($data['amount']) && (is_int($data['amount']) || is_float($data['amount']))
            ? (float) $data['amount']
            : null;

        return new NormalizedWebhookEvent(
            type: $type,
            providerPaymentId: $providerPaymentId,
            amount: $amount,
            raw: $payload,
        );
    }
}
