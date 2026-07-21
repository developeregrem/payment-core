<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

use Fewohbee\PaymentCore\Enum\CollectionMode;
use Fewohbee\PaymentCore\Enum\PaymentStatus;

final readonly class PaymentStatusSnapshot
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public PaymentStatus $status,
        public array $raw = [],
        public CollectionMode $collectionMode = CollectionMode::UNKNOWN,
        public ?string $providerPaymentMethod = null,
        public ?float $amount = null,
        public ?string $currency = null,
    ) {
    }
}
