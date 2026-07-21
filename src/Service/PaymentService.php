<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Service;

use Fewohbee\PaymentCore\Entity\PaymentTransaction;
use Fewohbee\PaymentCore\Dto\CreatePaymentRequest;
use Fewohbee\PaymentCore\Dto\NormalizedWebhookEvent;
use Fewohbee\PaymentCore\Dto\PaymentInitiation;
use Fewohbee\PaymentCore\Dto\PaymentStatusSnapshot;
use Fewohbee\PaymentCore\Dto\PaymentSyncResult;
use Fewohbee\PaymentCore\Enum\PaymentStatus;
use Fewohbee\PaymentCore\Enum\WebhookEventType;
use Fewohbee\PaymentCore\Event\PaymentCancelledEvent;
use Fewohbee\PaymentCore\Event\PaymentChargedBackEvent;
use Fewohbee\PaymentCore\Event\PaymentFailedEvent;
use Fewohbee\PaymentCore\Event\PaymentInitiatedEvent;
use Fewohbee\PaymentCore\Event\PaymentRefundedEvent;
use Fewohbee\PaymentCore\Event\PaymentRefundPendingEvent;
use Fewohbee\PaymentCore\Event\PaymentSettledEvent;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use Fewohbee\PaymentCore\Provider\PaymentProviderRegistry;
use Fewohbee\PaymentCore\Webhook\WebhookHandlerRegistry;
use Fewohbee\PaymentCore\Repository\PaymentTransactionRepository;
use Fewohbee\PaymentCore\Repository\PaymentWebhookInboxRepository;
use Fewohbee\PaymentCore\Entity\PaymentWebhookInbox;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PaymentService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly PaymentProviderRegistry $providerRegistry,
        private readonly WebhookHandlerRegistry $webhookHandlerRegistry,
        private readonly EntityManagerInterface $em,
        private readonly PaymentTransactionRepository $transactionRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        ?LoggerInterface $logger = null,
        private readonly ?PaymentWebhookInboxRepository $webhookInbox = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Initiate a payment via the currently active provider.
     * Returns the redirect URL to send the customer to.
     */
    public function initiate(CreatePaymentRequest $request): PaymentInitiation
    {
        $provider = $this->providerRegistry->getActive();
        $initiation = $provider->createPayment($request);

        $transaction = new PaymentTransaction(
            providerId: $provider->getId(),
            providerPaymentId: $initiation->providerPaymentId,
            externalReference: $request->externalReference,
            amount: $request->amount,
            currency: $request->currency,
            purpose: $request->purpose,
            intent: $request->intent,
            status: PaymentStatus::PENDING,
            kind: $request->kind,
        );

        if (null !== $request->returnUrl) {
            $transaction->setMetadata(['returnUrl' => $request->returnUrl]);
        }

        $this->em->persist($transaction);
        $this->em->flush();

        return $initiation;
    }

    /**
     * Pull the current status from the provider and persist any change.
     * Dispatches a domain event when the status transitions.
     */
    public function syncStatus(int $transactionId): PaymentStatus
    {
        $transaction = $this->transactionRepository->find($transactionId);
        if (!$transaction instanceof PaymentTransaction) {
            throw new \InvalidArgumentException(sprintf('PaymentTransaction #%d not found.', $transactionId));
        }

        return $this->syncTransaction($transaction);
    }

    public function syncTransaction(PaymentTransaction $transaction): PaymentStatus
    {
        return $this->syncTransactionResult($transaction)->status;
    }

    public function syncTransactionResult(PaymentTransaction $transaction): PaymentSyncResult
    {
        $previous = $transaction->getStatus();
        if ($previous->isTerminal() && !in_array($previous, [PaymentStatus::SETTLED, PaymentStatus::FAILED], true)) {
            return new PaymentSyncResult($previous, false, true);
        }

        $provider = $this->providerRegistry->get($transaction->getProviderId());
        $now = new \DateTimeImmutable();

        try {
            $snapshot = $provider->fetchPaymentStatus($transaction->getProviderPaymentId());
            $this->validateSnapshot($transaction, $snapshot);
        } catch (PaymentProviderException $e) {
            $this->logger->warning('Payment status fetch failed', [
                'transactionId' => $transaction->getId(),
                'providerId' => $transaction->getProviderId(),
                'error' => $e->getMessage(),
            ]);
            $transaction->markCheckFailed($now, $e->getMessage(), $this->retryAt($now, $transaction->getCheckFailureCount()));
            $this->em->flush();

            return new PaymentSyncResult($transaction->getStatus(), false, false, $e->getMessage());
        }

        $transaction->setCollectionDetails($snapshot->collectionMode, $snapshot->providerPaymentMethod);

        // A stale provider read must never undo a confirmed settlement. Only
        // explicit reversal states are allowed to move a settled transaction.
        $newStatus = PaymentStatus::SETTLED === $previous && in_array($snapshot->status, [
            PaymentStatus::PENDING,
            PaymentStatus::INITIATED,
            PaymentStatus::FAILED,
            PaymentStatus::CANCELLED,
        ], true) ? PaymentStatus::SETTLED : $snapshot->status;

        $this->applyStatus($transaction, $newStatus);
        $transaction->markCheckSucceeded($now, $this->nextCheckAt($now, $transaction->getStatus()));
        $this->em->flush();

        return new PaymentSyncResult($transaction->getStatus(), $previous !== $transaction->getStatus(), true);
    }

    /** @return PaymentTransaction[] */
    public function findPending(int $limit = 200): array
    {
        return $this->transactionRepository->findPending($limit);
    }

    /**
     * Handle an incoming webhook request. Returns true when an event was processed,
     * false when no handler is registered for the given providerId or the event is irrelevant.
     *
     * The host application decides which controller route exposes this method.
     */
    public function processWebhook(string $providerId, Request $request): bool
    {
        if (!$this->webhookHandlerRegistry->has($providerId)) {
            return false;
        }

        $handler = $this->webhookHandlerRegistry->get($providerId);
        $event = $handler->handle($request);
        if (null === $event) {
            return false;
        }

        if (null === $this->webhookInbox) {
            return $this->applyWebhookEvent($providerId, $event);
        }

        $inbox = new PaymentWebhookInbox($providerId, $event);
        try {
            $this->em->persist($inbox);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->em->clear();
            return true; // duplicate delivery was already accepted durably
        }

        $this->processInboxItem($inbox);

        // Accepted durably even when its transaction has not arrived yet.
        return true;
    }

    public function processPendingWebhooks(int $limit = 200): int
    {
        if (null === $this->webhookInbox) {
            return 0;
        }
        $processed = 0;
        foreach ($this->webhookInbox->findDue(new \DateTimeImmutable(), $limit) as $item) {
            if ($this->processInboxItem($item)) {
                ++$processed;
            }
        }

        return $processed;
    }

    private function processInboxItem(PaymentWebhookInbox $item): bool
    {
        if ($item->isProcessed()) {
            return true;
        }
        if ($this->applyWebhookEvent($item->getProviderId(), $item->toEvent())) {
            $item->markProcessed();
            $this->em->flush();
            return true;
        }
        $item->reschedule();
        $this->em->flush();

        return false;
    }

    private function applyWebhookEvent(string $providerId, NormalizedWebhookEvent $event): bool
    {
        $transaction = $this->transactionRepository->findOneByProviderAndProviderPaymentId(
            $providerId,
            $event->providerPaymentId,
        );
        if (!$transaction instanceof PaymentTransaction) {
            $this->logger->info('Webhook references unknown payment transaction; ignoring', [
                'providerId' => $providerId,
                'providerPaymentId' => $event->providerPaymentId,
            ]);

            return false;
        }

        $newStatus = match ($event->type) {
            WebhookEventType::INITIATED => PaymentStatus::INITIATED,
            WebhookEventType::SETTLED => PaymentStatus::SETTLED,
            WebhookEventType::FAILED => PaymentStatus::FAILED,
            WebhookEventType::CANCELLED => PaymentStatus::CANCELLED,
            WebhookEventType::REFUNDED => PaymentStatus::REFUNDED,
        };

        if (WebhookEventType::SETTLED === $event->type && null !== $event->amount
            && abs($event->amount - $transaction->getAmount()) > 0.009) {
            $this->logger->error('Rejected settled webhook with mismatching amount', [
                'transactionId' => $transaction->getId(),
                'expectedAmount' => $transaction->getAmount(),
                'actualAmount' => $event->amount,
            ]);

            return false;
        }

        $this->applyStatus($transaction, $newStatus);
        $now = new \DateTimeImmutable();
        $transaction->markCheckSucceeded($now, $this->nextCheckAt($now, $transaction->getStatus()));
        $this->em->flush();

        return true;
    }

    private function applyStatus(PaymentTransaction $transaction, PaymentStatus $newStatus): void
    {
        $previous = $transaction->getStatus();
        if ($previous === $newStatus && null === $transaction->getId()) {
            return;
        }

        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($transaction, $newStatus): void {
            if (null !== $transaction->getId()) {
                $em->lock($transaction, LockMode::PESSIMISTIC_WRITE);
                // lock() does not reload an already managed object. Refresh it
                // while holding the row lock so concurrent webhook/poll workers
                // cannot overwrite a transition committed while they waited.
                $em->refresh($transaction);
            }
            $effectiveStatus = $this->safeTransition($transaction->getStatus(), $newStatus);
            if ($transaction->getStatus() === $effectiveStatus) {
                return;
            }
            $transaction->setStatus($effectiveStatus);

            $event = match ($effectiveStatus) {
                PaymentStatus::INITIATED => new PaymentInitiatedEvent($transaction),
                PaymentStatus::SETTLED => new PaymentSettledEvent($transaction),
                PaymentStatus::FAILED => new PaymentFailedEvent($transaction),
                PaymentStatus::CANCELLED => new PaymentCancelledEvent($transaction),
                PaymentStatus::REFUND_PENDING => new PaymentRefundPendingEvent($transaction),
                PaymentStatus::REFUNDED => new PaymentRefundedEvent($transaction),
                PaymentStatus::CHARGED_BACK => new PaymentChargedBackEvent($transaction),
                default => null,
            };
            if (null !== $event) {
                $this->eventDispatcher->dispatch($event);
            }
            $em->flush();
        });
    }

    /** Prevent stale or out-of-order provider observations from regressing state. */
    private function safeTransition(PaymentStatus $current, PaymentStatus $requested): PaymentStatus
    {
        if (in_array($current, [PaymentStatus::REFUNDED, PaymentStatus::CHARGED_BACK], true)) {
            return $current;
        }
        if (PaymentStatus::SETTLED === $current && !in_array($requested, [
            PaymentStatus::REFUND_PENDING,
            PaymentStatus::REFUNDED,
            PaymentStatus::CHARGED_BACK,
        ], true)) {
            return $current;
        }
        if (PaymentStatus::REFUND_PENDING === $current && !in_array($requested, [
            PaymentStatus::SETTLED,
            PaymentStatus::REFUNDED,
            PaymentStatus::CHARGED_BACK,
        ], true)) {
            return $current;
        }

        return $requested;
    }

    private function validateSnapshot(PaymentTransaction $transaction, PaymentStatusSnapshot $snapshot): void
    {
        if (PaymentStatus::SETTLED !== $snapshot->status) {
            return;
        }
        if (null !== $snapshot->amount && abs($snapshot->amount - $transaction->getAmount()) > 0.009) {
            throw new PaymentProviderException(sprintf(
                'Provider settlement amount mismatch: expected %.2f, got %.2f.',
                $transaction->getAmount(),
                $snapshot->amount,
            ));
        }
        if (null !== $snapshot->currency && 0 !== strcasecmp($snapshot->currency, $transaction->getCurrency())) {
            throw new PaymentProviderException(sprintf(
                'Provider settlement currency mismatch: expected %s, got %s.',
                $transaction->getCurrency(),
                $snapshot->currency,
            ));
        }
    }

    private function retryAt(\DateTimeImmutable $now, int $previousFailureCount): \DateTimeImmutable
    {
        $seconds = min(21600, 60 * (2 ** min($previousFailureCount, 8)));

        return $now->modify(sprintf('+%d seconds', $seconds));
    }

    private function nextCheckAt(\DateTimeImmutable $now, PaymentStatus $status): ?\DateTimeImmutable
    {
        return match ($status) {
            PaymentStatus::PENDING, PaymentStatus::INITIATED => $now->modify('+5 minutes'),
            PaymentStatus::FAILED => $now->modify('+15 minutes'),
            PaymentStatus::SETTLED => $now->modify('+1 day'),
            PaymentStatus::REFUND_PENDING => $now->modify('+1 hour'),
            default => null,
        };
    }
}
