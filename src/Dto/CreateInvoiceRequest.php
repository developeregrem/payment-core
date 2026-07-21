<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Dto;

use Fewohbee\PaymentCore\Enum\PaymentKind;

/**
 * Request to create (and finalize) a provider invoice. Provider-agnostic; the
 * adapter maps it to the provider's invoice API and yields an
 * {@see InvoiceInitiation}. The opaque link to the host domain is
 * `externalReference` (as with payments).
 *
 * @see \Fewohbee\PaymentCore\Provider\InvoiceProviderInterface
 */
final readonly class CreateInvoiceRequest
{
    /**
     * @param InvoicePosition[] $positions
     * @param 'PERSON'|'ORGANIZATION' $customerType
     * @param array<string, string|int|float|bool|null> $metadata
     */
    public function __construct(
        public string $externalReference,
        public string $currency,
        public string $purpose,
        public string $customerEmail,
        public string $customerFirstName,
        public string $customerLastName,
        public BillingAddress $address,
        public array $positions,
        public ?string $companyName = null,
        public ?string $vatId = null,
        public string $customerType = 'PERSON',
        public bool $grossInvoice = true,
        public ?float $defaultTaxRatePercent = null,
        public bool $reverseCharge = false,
        public ?string $taxExemptNote = null,
        public ?int $paymentTermInDays = null,
        public ?\DateTimeImmutable $servicePeriodStart = null,
        public ?\DateTimeImmutable $servicePeriodEnd = null,
        public ?PaymentKind $kind = null,
        /** Provider customer id previously persisted by the host application. */
        public ?string $providerCustomerId = null,
        /** Stable customer reference; must not be an order/invoice reference. */
        public ?string $customerExternalReference = null,
        public array $metadata = [],
    ) {
    }
}
