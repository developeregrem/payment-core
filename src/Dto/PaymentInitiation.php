<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

final readonly class PaymentInitiation
{
    public function __construct(
        public string $providerPaymentId,
        public ?string $redirectUrl,
    ) {
    }
}
