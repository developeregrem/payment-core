<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

use Fewohbee\PaymentCore\Enum\WebhookEventType;

final readonly class NormalizedWebhookEvent
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public WebhookEventType $type,
        public string $providerPaymentId,
        public ?float $amount = null,
        public array $raw = [],
    ) {
    }
}
