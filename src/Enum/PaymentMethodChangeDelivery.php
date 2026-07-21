<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Enum;

enum PaymentMethodChangeDelivery: string
{
    case EMAIL = 'email';
    case LINK = 'link';
    case MANUAL = 'manual';
}
