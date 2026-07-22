<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Exception;

/**
 * The provider may have accepted an invoice write although its response was
 * lost. A known invoice id is safe to resume; without one a repeated POST is
 * deliberately forbidden because it could send a duplicate invoice.
 */
final class AmbiguousInvoiceCreationException extends PaymentProviderException
{
    public function __construct(
        public readonly ?string $providerInvoiceId,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function canResume(): bool
    {
        return null !== $this->providerInvoiceId && '' !== $this->providerInvoiceId;
    }
}
