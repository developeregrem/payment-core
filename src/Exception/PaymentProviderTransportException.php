<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Exception;

/**
 * The provider's response could not be received completely. For write
 * operations the remote action may already have been applied.
 */
class PaymentProviderTransportException extends PaymentProviderException
{
}
