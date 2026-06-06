<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

/**
 * Postal address of the invoiced customer (required for ZUGFeRD e-invoices).
 */
final readonly class BillingAddress
{
    public function __construct(
        public string $line,
        public string $zipCode,
        public string $city,
        public string $country = 'DE',
    ) {
    }
}
