<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Event;

use Fewohbee\PaymentCore\Entity\PaymentTransaction;

class PaymentSettledEvent
{
    public function __construct(
        public readonly PaymentTransaction $transaction,
    ) {
    }
}
