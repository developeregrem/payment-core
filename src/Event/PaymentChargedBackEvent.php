<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Event;

use Fewohbee\PaymentCore\Entity\PaymentTransaction;

final readonly class PaymentChargedBackEvent
{
    public function __construct(public PaymentTransaction $transaction)
    {
    }
}
