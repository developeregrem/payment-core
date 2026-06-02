<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

use Fewohbee\PaymentCore\Enum\PaymentIntent;
use Fewohbee\PaymentCore\Enum\PaymentKind;

final readonly class CreatePaymentRequest
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $purpose,
        public string $customerEmail,
        public string $customerFirstName,
        public string $customerLastName,
        public string $externalReference,
        public PaymentIntent $intent = PaymentIntent::PAYMENT,
        public ?string $returnUrl = null,
        /**
         * Caller-provided classification (deposit / balance / full).
         * Core never interprets this — it is persisted for reporting and UI grouping.
         */
        public ?PaymentKind $kind = null,
    ) {
    }
}
