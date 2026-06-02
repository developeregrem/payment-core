<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Enum;

enum WebhookEventType: string
{
    case INITIATED = 'initiated';
    case SETTLED = 'settled';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
}
