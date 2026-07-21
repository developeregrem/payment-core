<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Provider;

use Fewohbee\PaymentCore\Dto\PaymentMethodChangeResult;
use Fewohbee\PaymentCore\Enum\PaymentMethodChangeDelivery;

interface PaymentMethodManagementProviderInterface
{
    public function requestPaymentMethodChange(
        string $providerCustomerId,
        PaymentMethodChangeDelivery $delivery = PaymentMethodChangeDelivery::EMAIL,
    ): PaymentMethodChangeResult;

    /** @return list<string> Provider-defined payment method identifiers. */
    public function getCustomerPaymentMethods(string $providerCustomerId): array;
}
