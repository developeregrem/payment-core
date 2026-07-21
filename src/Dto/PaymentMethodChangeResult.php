<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

use Fewohbee\PaymentCore\Enum\PaymentMethodChangeDelivery;

final readonly class PaymentMethodChangeResult
{
    public function __construct(
        public PaymentMethodChangeDelivery $delivery,
        public ?string $actionUrl = null,
    ) {
    }
}
