<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Webhook;

use Fewohbee\PaymentCore\Dto\NormalizedWebhookEvent;
use Fewohbee\PaymentCore\Exception\WebhookSignatureException;
use Symfony\Component\HttpFoundation\Request;

interface WebhookHandlerInterface
{
    /** Stable identifier for the provider this handler belongs to. */
    public function getProviderId(): string;

    /**
     * Verify the request and produce a normalized event for the core to dispatch.
     * Returns null if the event type is recognized but not relevant to us.
     *
     * @throws WebhookSignatureException when signature verification fails
     */
    public function handle(Request $request): ?NormalizedWebhookEvent;
}
