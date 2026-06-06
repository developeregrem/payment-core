<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

/**
 * Result of creating + finalizing a provider invoice.
 * `providerPaymentId` is the id of the payment that settles the invoice
 * (used for status polling); `redirectUrl` is its hosted pay-link.
 */
final readonly class InvoiceInitiation
{
    public function __construct(
        public string $invoiceId,
        public ?string $invoiceNumber,
        public ?string $providerPaymentId,
        public ?string $redirectUrl,
    ) {
    }
}
