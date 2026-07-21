<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

use Fewohbee\PaymentCore\Enum\PaymentStatus;

final readonly class PaymentSyncResult
{
    public function __construct(
        public PaymentStatus $status,
        public bool $changed,
        public bool $successful,
        public ?string $error = null,
    ) {
    }
}
