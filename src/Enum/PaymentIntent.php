<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Enum;

enum PaymentIntent: string
{
    case PAYMENT = 'payment';
    case AUTHORIZATION = 'authorization';
}
