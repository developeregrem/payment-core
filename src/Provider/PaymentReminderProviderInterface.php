<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Provider;

use Fewohbee\PaymentCore\Exception\PaymentProviderException;

/**
 * Optional capability for providers that can resend/remind an existing payment
 * request without creating a new invoice or payment.
 */
interface PaymentReminderProviderInterface
{
    /**
     * Send a payment reminder for an existing provider payment.
     *
     * @throws PaymentProviderException
     */
    public function sendPaymentReminder(string $providerPaymentId): void;
}
