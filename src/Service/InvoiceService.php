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
use Fewohbee\PaymentCore\Provider\PaymentProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentProviderRegistry;
use Fewohbee\PaymentCore\Provider\PaymentReminderProviderInterface;
use Fewohbee\PaymentCore\Repository\PaymentTransactionRepository;
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
        private readonly ?PaymentTransactionRepository $transactionRepository = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create + finalize an invoice and persist a PaymentTransaction for it.
     *
     * @throws PaymentProviderException when the active provider can't invoice
     */
    public function createInvoice(CreateInvoiceRequest $request, ?string $providerId = null): InvoiceInitiation
    {
        $provider = $this->invoiceProvider($providerId);
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

        // A provider POST/finalize can succeed even if the worker loses its
        // response or crashes before the Order stores the invoice id. On retry
        // the adapter recovers that invoice; reuse its already committed local
        // transaction instead of violating the provider/payment unique key.
        $existing = $this->transactionRepository?->findOneByProviderAndProviderPaymentId(
            $provider->getId(),
            $providerPaymentId,
        );
        if ($existing instanceof PaymentTransaction) {
            $expectedAmount = $this->grossTotal($request);
            if ($existing->getExternalReference() !== $request->externalReference
                || abs($existing->getAmount() - $expectedAmount) > 0.009
                || 0 !== strcasecmp($existing->getCurrency(), $request->currency)) {
                throw new PaymentProviderException('Recovered invoice payment conflicts with an existing local transaction.');
            }

            return $initiation;
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
            collectionMode: $initiation->collectionMode,
            providerPaymentMethod: $initiation->providerPaymentMethod,
        );
        $transaction->setMetadata(array_filter([
            'invoiceId' => $initiation->invoiceId,
            'invoiceNumber' => $initiation->invoiceNumber,
            'providerCustomerId' => $initiation->providerCustomerId,
        ], static fn ($v) => null !== $v));

        $this->em->persist($transaction);
        $this->em->flush();

        return $initiation;
    }

    /**
     * @throws PaymentProviderException
     */
    public function downloadInvoice(string $invoiceId, ?string $providerId = null): InvoiceDocument
    {
        return $this->invoiceProvider($providerId)->downloadInvoice($invoiceId);
    }

    /**
     * Send a payment reminder for the payment transaction created from an
     * existing invoice.
     *
     * @throws PaymentProviderException
     */
    public function sendPaymentReminder(string $externalReference, ?string $invoiceId = null): void
    {
        $transaction = $this->findInvoiceTransaction($externalReference, $invoiceId);
        if (!$transaction instanceof PaymentTransaction) {
            throw new PaymentProviderException(sprintf(
                'No payment transaction found for external reference "%s"%s.',
                $externalReference,
                null !== $invoiceId ? sprintf(' and invoice "%s"', $invoiceId) : ''
            ));
        }

        $provider = $this->providerRegistry->get($transaction->getProviderId());
        if (!$provider instanceof PaymentReminderProviderInterface) {
            throw new PaymentProviderException(sprintf(
                'Payment provider "%s" does not support payment reminders.',
                $provider->getId()
            ));
        }

        $provider->sendPaymentReminder($transaction->getProviderPaymentId());
    }

    private function invoiceProvider(?string $providerId = null): InvoiceProviderInterface&PaymentProviderInterface
    {
        $provider = null === $providerId ? $this->providerRegistry->getActive() : $this->providerRegistry->get($providerId);
        if (!$provider instanceof InvoiceProviderInterface || !$provider->supports(ProviderCapability::INVOICE)) {
            throw new PaymentProviderException(sprintf(
                'Active payment provider "%s" does not support invoicing.',
                $provider->getId()
            ));
        }

        return $provider;
    }

    private function findInvoiceTransaction(string $externalReference, ?string $invoiceId): ?PaymentTransaction
    {
        $repository = $this->em->getRepository(PaymentTransaction::class);
        /** @var PaymentTransaction[] $transactions */
        $transactions = $repository->findBy(['externalReference' => $externalReference], ['createdAt' => 'DESC']);

        foreach ($transactions as $transaction) {
            if (!$transaction instanceof PaymentTransaction) {
                continue;
            }

            if (null === $invoiceId) {
                return $transaction;
            }

            $metadata = $transaction->getMetadata();
            if (($metadata['invoiceId'] ?? null) === $invoiceId) {
                return $transaction;
            }
        }

        return null;
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
