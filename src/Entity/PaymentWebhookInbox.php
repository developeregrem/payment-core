<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fewohbee\PaymentCore\Dto\NormalizedWebhookEvent;
use Fewohbee\PaymentCore\Enum\WebhookEventType;
use Fewohbee\PaymentCore\Repository\PaymentWebhookInboxRepository;

#[ORM\Entity(repositoryClass: PaymentWebhookInboxRepository::class)]
#[ORM\Table(name: 'payment_webhook_inbox')]
#[ORM\UniqueConstraint(name: 'uniq_payment_webhook_event', columns: ['event_key'])]
#[ORM\Index(name: 'idx_payment_webhook_due', columns: ['processed_at', 'next_attempt_at'])]
class PaymentWebhookInbox
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $eventKey;
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $providerId;
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $providerPaymentId;
    #[ORM\Column(type: Types::STRING, length: 30, enumType: WebhookEventType::class)]
    private WebhookEventType $type;
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $amount = null;
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $attempts = 0;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $nextAttemptAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(string $providerId, NormalizedWebhookEvent $event)
    {
        $this->providerId = $providerId;
        $this->providerPaymentId = $event->providerPaymentId;
        $this->type = $event->type;
        $this->amount = null === $event->amount ? null : number_format($event->amount, 2, '.', '');
        $this->payload = $event->raw;
        $canonical = json_encode($event->raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->eventKey = hash('sha256', $providerId."\0".$event->type->value."\0".$event->providerPaymentId."\0".$canonical);
        $this->createdAt = new \DateTimeImmutable();
        $this->nextAttemptAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getProviderId(): string { return $this->providerId; }
    public function getEventKey(): string { return $this->eventKey; }
    public function isProcessed(): bool { return null !== $this->processedAt; }

    public function toEvent(): NormalizedWebhookEvent
    {
        return new NormalizedWebhookEvent($this->type, $this->providerPaymentId, null === $this->amount ? null : (float) $this->amount, $this->payload);
    }

    public function markProcessed(): self
    {
        $this->processedAt = new \DateTimeImmutable();
        return $this;
    }

    public function reschedule(): self
    {
        ++$this->attempts;
        $seconds = min(3600, 30 * (2 ** min($this->attempts, 7)));
        $this->nextAttemptAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $seconds));
        return $this;
    }
}
