<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Enum;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case INITIATED = 'initiated';
    case SETTLED = 'settled';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REFUND_PENDING = 'refund_pending';
    case REFUNDED = 'refunded';
    case CHARGED_BACK = 'charged_back';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SETTLED, self::FAILED, self::CANCELLED, self::REFUNDED, self::CHARGED_BACK => true,
            self::PENDING, self::INITIATED, self::REFUND_PENDING => false,
        };
    }
}
