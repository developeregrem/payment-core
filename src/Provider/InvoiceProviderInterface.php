<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Provider;

use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\InvoiceDocument;
use Fewohbee\PaymentCore\Dto\InvoiceInitiation;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;

/**
 * Optional capability: a payment provider that can issue invoices (e.g. a
 * ZUGFeRD e-invoice). A provider opts in by implementing this interface in
 * addition to {@see PaymentProviderInterface} and returning true for
 * {@see PaymentProviderInterface::supports()} with ProviderCapability::INVOICE.
 *
 * Consumers that don't need invoicing (e.g. fewohbee, which generates its own
 * invoices) simply never call this — the payment flow is unaffected.
 */
interface InvoiceProviderInterface
{
    /**
     * Create and finalize an invoice with the provider.
     *
     * @throws PaymentProviderException
     */
    public function createInvoice(CreateInvoiceRequest $request): InvoiceInitiation;

    /**
     * Download a finalized invoice document (e.g. the ZUGFeRD PDF).
     *
     * @throws PaymentProviderException
     */
    public function downloadInvoice(string $invoiceId): InvoiceDocument;
}
