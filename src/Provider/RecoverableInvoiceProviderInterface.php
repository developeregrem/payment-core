<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Provider;

use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\InvoiceInitiation;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;

/** Optional provider-neutral capability for resuming a known remote invoice. */
interface RecoverableInvoiceProviderInterface
{
    /**
     * Finalize/read a previously created provider invoice without issuing a new
     * invoice. Implementations must verify that it belongs to the request.
     *
     * @throws PaymentProviderException
     */
    public function recoverInvoice(CreateInvoiceRequest $request, string $providerInvoiceId): InvoiceInitiation;
}
