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
 * Note: not currently wired up to any controller route. This class is registered as an
 * extension point so a future opt-in (per-instance Payactive portal setup) can activate it.
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

        $eventType = isset($payload['event_type']) && is_string($payload['event_type']) ? $payload['event_type'] : '';
        $type = match ($eventType) {
            'payment.initiated' => WebhookEventType::INITIATED,
            'payment.settled' => WebhookEventType::SETTLED,
            // payment.failed is offered in the Payactive portal UI even though it
            // is missing from the public docs (confirmed by the Payactive team).
            'payment.failed' => WebhookEventType::FAILED,
            // Forward-compatible: existence of these two is not yet confirmed by
            // Payactive. If they never fire it is harmless; if they do, we map them.
            'payment.cancelled' => WebhookEventType::CANCELLED,
            'payment.refunded' => WebhookEventType::REFUNDED,
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
