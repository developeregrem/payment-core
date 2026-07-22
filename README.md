# fewohbee/payment-core

Provider-agnostic payment module with a Payactive adapter, packaged as a Symfony
bundle so it can be reused by multiple apps.

## What's inside

- `PaymentCoreBundle` — registers all services, the autoconfiguration tags and the Doctrine mapping
- `Provider/` — `PaymentProviderInterface` + `PaymentProviderRegistry`
- `Webhook/` — `WebhookHandlerInterface` + `WebhookHandlerRegistry`
- `Service/PaymentService` — `initiate()`, `syncStatus()`, `findPending()`, `processWebhook()`
- `Command/ReconcilePendingPaymentsCommand` — `payment:reconcile-pending` (cron)
- `Command/SandboxTestCommand` — `payment:sandbox-test` (manual sandbox driver)
- `Dto/`, `Enum/`, `Event/`, `Exception/`
- `Adapter/Payactive/` — `PayactiveProvider`, `PayactiveClient`, `PayactiveWebhookHandler`
- `Entity/PaymentTransaction` + `Repository/PaymentTransactionRepository`

The opaque link to the host domain is `PaymentTransaction.externalReference`
(no FK to host entities — keeps the package decoupled). Subscribe to
`PaymentSettledEvent` / `PaymentFailedEvent` / `PaymentCancelledEvent` /
`PaymentRefundedEvent` in the host app to react to status changes.

## Install

Add the VCS repository and require the package in the consuming app's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/developeregrem/payment-core" }
    ],
    "require": {
        "fewohbee/payment-core": "^0.1"
    }
}
```

```sh
composer require fewohbee/payment-core
```

## Wire it up

1. **Register the bundle** in `config/bundles.php`:

   ```php
   return [
       // ...
       Fewohbee\PaymentCore\PaymentCoreBundle::class => ['all' => true],
   ];
   ```

   That alone provides all services, the `app.payment.provider` /
   `app.payment.webhook_handler` autoconfiguration tags **and** the Doctrine ORM
   mapping for `PaymentTransaction`. No service copy-paste, no `doctrine.yaml`
   mapping entry required.

2. **Env vars** (the bundle config defaults to these):

   ```dotenv
   PAYMENT_PROVIDER=payactive
   PAYACTIVE_API_BASE_URL=https://api.sandbox.payactive.app
   PAYACTIVE_API_KEY=...
   PAYACTIVE_WEBHOOK_SECRET=...
   ```

   To override without env vars, add `config/packages/payment_core.yaml`:

   ```yaml
   payment_core:
       active_provider: payactive
       payactive:
           api_key: '%env(PAYACTIVE_API_KEY)%'
           base_url: '%env(PAYACTIVE_API_BASE_URL)%'
           webhook_secret: '%env(PAYACTIVE_WEBHOOK_SECRET)%'
   ```

3. **Generate a migration** in the host app (`doctrine:migrations:diff`) — the
   `payment_transactions` table lives in the host app's schema. The package does
   not ship migrations.

4. **Webhook endpoint (optional)** — the package ships `processWebhook()` and the
   Payactive HMAC handler but no controller route. Apps that want push updates
   add a thin controller calling `PaymentService::processWebhook($providerId, $request)`
   (outside the firewall; the HMAC signature is the authentication). The handler
   maps `payment.initiated`, `payment.settled` and `payment.failed`
   (`payment.cancelled` / `payment.refunded` are mapped forward-compatibly).

> A reference YAML snippet still lives in [`config/services.dist.yaml`](config/services.dist.yaml)
> for non-Flex setups that cannot register the bundle — prefer the bundle.

## Status sync

Payactive has no redirect-back; status is resolved by polling
(`payment:reconcile-pending`, cron) and/or the webhook handler. Keep the cron
running as a safety net even when the webhook is wired.

**Known limitation:** polling stops once a transaction reaches a terminal status
(e.g. `SETTLED`). A refund/chargeback that happens *after* settlement is not
re-detected by polling; rely on a `payment.refunded` webhook (if your account
emits it) or handle it manually. See the integration plan for the open
provider questions.

## Invoice retry safety

Invoice creation is not treated like an ordinary retryable HTTP request. A lost
response can mean that the provider accepted the invoice even though the caller
observed a timeout. Provider adapters therefore report such writes with
`AmbiguousInvoiceCreationException`:

- with a provider invoice id, the host persists the id and resumes that exact
  invoice through `RecoverableInvoiceProviderInterface`;
- without an id, the host must stop automatic creation and require an operator
  to match the external reference at the provider first.

This contract is provider-neutral and applies to future Stripe or other
adapters as well. It prevents an infrastructure retry from becoming a second
legally distinct invoice.

## Tests

```sh
composer install
vendor/bin/phpunit
```

## License

Proprietary — all rights reserved.
