<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Provider;

use Fewohbee\PaymentCore\Dto\CreatePaymentRequest;
use Fewohbee\PaymentCore\Dto\PaymentInitiation;
use Fewohbee\PaymentCore\Dto\PaymentStatusSnapshot;
use Fewohbee\PaymentCore\Enum\ProviderCapability;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;

interface PaymentProviderInterface
{
    /** Stable identifier for this provider (e.g. "payactive", "stripe"). */
    public function getId(): string;

    /** Whether this provider supports the given capability. */
    public function supports(ProviderCapability $capability): bool;

    /**
     * Initiate a payment with the provider.
     *
     * @throws PaymentProviderException
     */
    public function createPayment(CreatePaymentRequest $request): PaymentInitiation;

    /**
     * Fetch the current status of a previously created payment from the provider.
     *
     * @throws PaymentProviderException
     */
    public function fetchPaymentStatus(string $providerPaymentId): PaymentStatusSnapshot;
}
