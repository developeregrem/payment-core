<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Exception;

/** A provider returned a definite non-successful HTTP response. */
class PaymentProviderHttpException extends PaymentProviderException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
