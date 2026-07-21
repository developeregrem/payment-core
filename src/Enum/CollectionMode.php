<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Enum;

/**
 * Describes how an already created receivable is collected. This is deliberately
 * provider-neutral: adapters may map any concrete payment method to one of these
 * modes without leaking provider-specific names into the host application.
 */
enum CollectionMode: string
{
    case HOSTED_ACTION = 'hosted_action';
    case AUTOMATIC = 'automatic';
    case OFFLINE_TRANSFER = 'offline_transfer';
    case UNKNOWN = 'unknown';
}
