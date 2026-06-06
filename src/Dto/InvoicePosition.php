<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

/**
 * A single invoice line item. `unitPrice` is gross or net depending on the
 * request's `grossInvoice` flag. `taxRatePercent` overrides the invoice's
 * default tax rate for this position when set (e.g. 19.0, or 0.0 for §19).
 */
final readonly class InvoicePosition
{
    public function __construct(
        public string $description,
        public float $quantity,
        public float $unitPrice,
        public ?float $taxRatePercent = null,
    ) {
    }
}
