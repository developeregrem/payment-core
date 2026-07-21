<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Enum;

enum ProviderCapability: string
{
    case ONLINE_PAYMENT = 'online_payment';
    case DIRECT_DEBIT = 'direct_debit';
    case CARD_PREAUTH = 'card_preauth';
    case CARD_PAYMENT = 'card_payment';
    case REFUND = 'refund';
    case INVOICE = 'invoice';
    case PAYMENT_METHOD_MANAGEMENT = 'payment_method_management';
    case AUTOMATIC_COLLECTION = 'automatic_collection';
    case OFFLINE_TRANSFER = 'offline_transfer';
}
