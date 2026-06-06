<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\InvoiceDocument;
use Fewohbee\PaymentCore\Dto\InvoiceInitiation;
use Fewohbee\PaymentCore\Entity\PaymentTransaction;
use Fewohbee\PaymentCore\Enum\PaymentIntent;
use Fewohbee\PaymentCore\Enum\PaymentStatus;
use Fewohbee\PaymentCore\Enum\ProviderCapability;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use Fewohbee\PaymentCore\Provider\InvoiceProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentProviderRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates invoice creation through the active provider (if it supports
 * invoicing) and records a {@see PaymentTransaction} so the existing settle /
 * poll / webhook machinery tracks the invoice's payment unchanged.
 */
class InvoiceService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly PaymentProviderRegistry $providerRegistry,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create + finalize an invoice and persist a PaymentTransaction for it.
     *
     * @throws PaymentProviderException when the active provider can't invoice
     */
    public function createInvoice(CreateInvoiceRequest $request): InvoiceInitiation
    {
        $provider = $this->invoiceProvider();
        $initiation = $provider->createInvoice($request);

        // The invoice is settled via its payment; poll by that payment id.
        // Fall back to the invoice id only if the provider returned none
        // (degraded — flagged in the sandbox gate).
        $providerPaymentId = $initiation->providerPaymentId ?? $initiation->invoiceId;
        if (null === $initiation->providerPaymentId) {
            $this->logger->warning('Invoice has no associated payment id; status polling may not work', [
                'invoiceId' => $initiation->invoiceId,
            ]);
        }

        $transaction = new PaymentTransaction(
            providerId: $provider->getId(),
            providerPaymentId: $providerPaymentId,
            externalReference: $request->externalReference,
            amount: $this->grossTotal($request),
            currency: $request->currency,
            purpose: $request->purpose,
            intent: PaymentIntent::PAYMENT,
            status: PaymentStatus::PENDING,
            kind: $request->kind,
        );
        $transaction->setMetadata(array_filter([
            'invoiceId' => $initiation->invoiceId,
            'invoiceNumber' => $initiation->invoiceNumber,
        ], static fn ($v) => null !== $v));

        $this->em->persist($transaction);
        $this->em->flush();

        return $initiation;
    }

    /**
     * @throws PaymentProviderException
     */
    public function downloadInvoice(string $invoiceId): InvoiceDocument
    {
        return $this->invoiceProvider()->downloadInvoice($invoiceId);
    }

    private function invoiceProvider(): InvoiceProviderInterface&\Fewohbee\PaymentCore\Provider\PaymentProviderInterface
    {
        $provider = $this->providerRegistry->getActive();
        if (!$provider instanceof InvoiceProviderInterface || !$provider->supports(ProviderCapability::INVOICE)) {
            throw new PaymentProviderException(sprintf(
                'Active payment provider "%s" does not support invoicing.',
                $provider->getId()
            ));
        }

        return $provider;
    }

    private function grossTotal(CreateInvoiceRequest $request): float
    {
        $sum = 0.0;
        foreach ($request->positions as $position) {
            $sum += $position->quantity * $position->unitPrice;
        }

        return round($sum, 2);
    }
}
