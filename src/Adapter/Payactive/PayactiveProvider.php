<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Adapter\Payactive;

use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\CreatePaymentRequest;
use Fewohbee\PaymentCore\Dto\InvoiceDocument;
use Fewohbee\PaymentCore\Dto\InvoiceInitiation;
use Fewohbee\PaymentCore\Dto\InvoicePosition;
use Fewohbee\PaymentCore\Dto\PaymentMethodChangeResult;
use Fewohbee\PaymentCore\Dto\PaymentInitiation;
use Fewohbee\PaymentCore\Dto\PaymentStatusSnapshot;
use Fewohbee\PaymentCore\Enum\CollectionMode;
use Fewohbee\PaymentCore\Enum\PaymentMethodChangeDelivery;
use Fewohbee\PaymentCore\Enum\PaymentStatus;
use Fewohbee\PaymentCore\Enum\ProviderCapability;
use Fewohbee\PaymentCore\Exception\AmbiguousInvoiceCreationException;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use Fewohbee\PaymentCore\Provider\InvoiceProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentMethodManagementProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentReminderProviderInterface;
use Fewohbee\PaymentCore\Provider\RecoverableInvoiceProviderInterface;

/**
 * Payactive adapter. Self-contained — depends only on the Core Payment module
 * and Symfony HTTP/Logger primitives. Extractable as a Composer package.
 *
 * Payment flow (per Payactive's canonical recipe):
 *   1) POST /customers       → customerId
 *   2) POST /payments        → paymentId
 *   3) GET  /payments/{id}/payment-link → URL for the guest
 *   4) GET  /payments/{id}   → polled state for status sync
 *
 * Invoice flow (optional capability, e.g. for the portal):
 *   1) ensure customer (with address + vatId, ZUGFeRD requires them)
 *   2) POST /invoices                       → invoice (DRAFT)
 *   3) POST /invoices/{id}/actions/finalize → invoiceNumber + paymentId (ZUGFeRD)
 *   4) GET  /payments/{paymentId}/payment-link → pay-link
 */
class PayactiveProvider implements PaymentProviderInterface, InvoiceProviderInterface, RecoverableInvoiceProviderInterface, PaymentReminderProviderInterface, PaymentMethodManagementProviderInterface
{
    public const ID = 'payactive';

    /**
     * @param list<string> $paymentMethods Methods offered on POST /payments
     *   (payment-first). The array lets the payer pick; CUSTOMERS_CHOICE is
     *   valid here (unlike on the customer).
     * @param string $customerPaymentMethod Single, valid method stored on the
     *   customer (PAPERLESS|ONLINE_PAYMENT|MANUAL_PAYMENT|DIRECT_DEBIT).
     *   CUSTOMERS_CHOICE is NOT accepted by Payactive on the customer, so it
     *   must be a concrete method. This is what an invoice-first payment uses,
     *   since invoices have no per-request payment method.
     * @param list<string> $invoicePaymentMethods Methods offered by an invoice
     *   payment flow. Payactive still gives an existing direct-debit mandate
     *   precedence even when DIRECT_DEBIT is not included here.
     */
    public function __construct(
        private readonly PayactiveClient $client,
        private readonly array $paymentMethods = ['CUSTOMERS_CHOICE'],
        private readonly ?string $creditorBankAccountId = null,
        private readonly string $customerPaymentMethod = 'ONLINE_PAYMENT',
        private readonly array $invoicePaymentMethods = ['ONLINE_PAYMENT', 'CREDIT_CARD'],
    ) {
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function supports(ProviderCapability $capability): bool
    {
        return match ($capability) {
            ProviderCapability::ONLINE_PAYMENT,
            ProviderCapability::DIRECT_DEBIT,
            ProviderCapability::CARD_PAYMENT,
            ProviderCapability::PAYMENT_METHOD_MANAGEMENT,
            ProviderCapability::AUTOMATIC_COLLECTION,
            ProviderCapability::OFFLINE_TRANSFER => true,
            ProviderCapability::INVOICE => null !== $this->creditorBankAccountId && '' !== $this->creditorBankAccountId,
            // Mandate/card details stay in Payactive's hosted customer flow;
            // pre-authorization and merchant-initiated refunds are not exposed.
            default => false,
        };
    }

    public function createPayment(CreatePaymentRequest $request): PaymentInitiation
    {
        $customerId = $this->client->findCustomerIdByEmail($request->customerEmail)
            ?? $this->client->createCustomer([
                'emailAddress' => $request->customerEmail,
                'firstName' => $request->customerFirstName,
                'lastName' => $request->customerLastName,
                'type' => 'PERSON',
                'paymentMethod' => $this->customerPaymentMethod(),
                'invitationType' => 'LINK',
                'externalRef' => $request->externalReference,
            ]);

        $paymentId = $this->client->createPayment([
            'paymentType' => 'PAYMENT_REQUEST',
            'customerId' => $customerId,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'purpose' => $request->purpose,
            'externalReference' => $request->externalReference,
            'paymentMethod' => $this->paymentMethods,
            'paymentNotifications' => 'EMAIL',
        ]);

        $redirectUrl = $this->client->getPaymentLink($paymentId);

        return new PaymentInitiation(
            providerPaymentId: $paymentId,
            redirectUrl: $redirectUrl,
        );
    }

    public function createInvoice(CreateInvoiceRequest $request): InvoiceInitiation
    {
        if (null === $this->creditorBankAccountId || '' === $this->creditorBankAccountId) {
            throw new PaymentProviderException('Payactive: creditor bank account id is not configured (PAYACTIVE_CREDITOR_BANK_ACCOUNT_ID).');
        }

        $customerId = $this->ensureInvoiceCustomer($request);

        $positions = array_map($this->mapPosition(...), $request->positions);

        $payload = [
            'customerId' => $customerId,
            'creditorBankAccountId' => $this->creditorBankAccountId,
            'positions' => $positions,
            'grossInvoice' => $request->grossInvoice,
            'reverseCharge' => $request->reverseCharge,
        ];
        $invoicePaymentMethods = $this->invoicePaymentMethods();
        if ([] !== $invoicePaymentMethods) {
            $payload['allowedPaymentMethods'] = $invoicePaymentMethods;
        }
        // This first value is the provider-side idempotency/recovery key and
        // cannot be replaced by optional caller metadata (array union semantics).
        $invoiceMetadata = ['externalReference' => $request->externalReference] + $request->metadata;
        if ([] !== $invoiceMetadata) {
            $payload['metadata'] = [];
            foreach ($invoiceMetadata as $key => $value) {
                if (null === $value) {
                    continue;
                }
                $payload['metadata'][] = [
                    'key' => (string) $key,
                    'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                    'publicVisible' => false,
                ];
            }
        }
        // Only send a default tax rate when no position carries its own — sending
        // both is what the working portal-UI invoice does NOT do. Positions with
        // an explicit taxRate define the rate per line.
        $positionsHaveTax = [] !== array_filter($positions, static fn (array $p): bool => isset($p['taxRate']));
        if (!$positionsHaveTax && null !== $request->defaultTaxRatePercent) {
            $payload['defaultTaxRate'] = [
                'rate' => $request->defaultTaxRatePercent,
                'description' => $this->taxDescription($request->defaultTaxRatePercent, $request->taxExemptNote),
            ];
        }
        if (null !== $request->paymentTermInDays) {
            $payload['paymentTermInDays'] = $request->paymentTermInDays;
        }
        // ZUGFeRD/Factur-X requires a service/delivery date; finalize fails (500)
        // without one. Default to today when the caller gives none (the Payactive
        // portal UI does the same).
        $payload['servicePeriodStart'] = ($request->servicePeriodStart ?? new \DateTimeImmutable('today'))->format('Y-m-d');
        if (null !== $request->servicePeriodEnd) {
            $payload['servicePeriodEnd'] = $request->servicePeriodEnd->format('Y-m-d');
        }

        $existingInvoice = $this->client->findInvoiceByMetadata('externalReference', $request->externalReference);
        if (null !== $existingInvoice) {
            return $this->resumeInvoiceSafely($existingInvoice, $customerId);
        }

        try {
            $created = $this->client->createInvoice($payload);
            $invoiceId = is_string($created['id'] ?? null) ? $created['id'] : '';
            if ('' === $invoiceId) {
                throw new \UnexpectedValueException('Payactive returned no invoice id.');
            }
        } catch (\Throwable $e) {
            // POST /invoices may have succeeded before the response was lost.
            // There is no provider id yet, so another automatic POST is unsafe.
            throw new AmbiguousInvoiceCreationException(
                null,
                'Payactive: invoice creation result is ambiguous; automatic creation is blocked to prevent duplicates.',
                $e,
            );
        }

        try {
            $finalized = $this->client->finalizeInvoice($invoiceId);

            return $this->invoiceInitiation($invoiceId, $finalized, $customerId);
        } catch (\Throwable $e) {
            // The draft id is known: persist it in the caller and resume that
            // exact invoice on retry instead of ever calling POST /invoices again.
            throw new AmbiguousInvoiceCreationException(
                $invoiceId,
                sprintf('Payactive: invoice %s exists but finalization/details are incomplete; safe recovery is possible.', $invoiceId),
                $e,
            );
        }
    }

    public function recoverInvoice(CreateInvoiceRequest $request, string $providerInvoiceId): InvoiceInitiation
    {
        $customerId = $this->ensureInvoiceCustomer($request);
        $invoice = $this->client->getInvoice($providerInvoiceId);
        if (!$this->hasMetadata($invoice, 'externalReference', $request->externalReference)) {
            throw new PaymentProviderException(sprintf(
                'Payactive: invoice %s does not belong to external reference %s.',
                $providerInvoiceId,
                $request->externalReference,
            ));
        }

        return $this->resumeInvoiceSafely($invoice, $customerId);
    }

    /** @param array<string, mixed> $invoice */
    private function resumeInvoiceSafely(array $invoice, string $customerId): InvoiceInitiation
    {
        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : '';
        try {
            return $this->resumeInvoice($invoice, $customerId);
        } catch (\Throwable $e) {
            throw new AmbiguousInvoiceCreationException(
                '' !== $invoiceId ? $invoiceId : null,
                '' !== $invoiceId
                    ? sprintf('Payactive: invoice %s recovery is incomplete but remains safe to retry.', $invoiceId)
                    : 'Payactive: recovered invoice has no usable id; automatic creation is blocked.',
                $e,
            );
        }
    }

    /** @param array<string, mixed> $invoice */
    private function hasMetadata(array $invoice, string $key, string $value): bool
    {
        foreach (is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [] as $metadata) {
            if (is_array($metadata)
                && ($metadata['key'] ?? null) === $key
                && (string) ($metadata['value'] ?? '') === $value
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $invoice */
    private function resumeInvoice(array $invoice, string $customerId): InvoiceInitiation
    {
        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : '';
        if ('' === $invoiceId) {
            throw new PaymentProviderException('Payactive: recovered invoice is missing its id.');
        }
        $status = is_string($invoice['status'] ?? null) ? $invoice['status'] : '';
        $finalized = 'DRAFT' === $status ? $this->client->finalizeInvoice($invoiceId) : $invoice;

        return $this->invoiceInitiation($invoiceId, $finalized, $customerId);
    }

    /** @param array<string, mixed> $finalized */
    private function invoiceInitiation(string $invoiceId, array $finalized, string $customerId): InvoiceInitiation
    {

        $invoiceNumber = isset($finalized['invoiceNumber']) && is_string($finalized['invoiceNumber'])
            ? $finalized['invoiceNumber'] : null;
        $paymentId = isset($finalized['paymentId']) && is_string($finalized['paymentId'])
            ? $finalized['paymentId'] : null;
        $redirectUrl = null !== $paymentId ? $this->client->getPaymentLink($paymentId) : null;
        $paymentMethod = null;
        if (null !== $paymentId) {
            $paymentMethod = $this->paymentMethodFromPayload($this->client->getPayment($paymentId));
        }

        return new InvoiceInitiation(
            invoiceId: $invoiceId,
            invoiceNumber: $invoiceNumber,
            providerPaymentId: $paymentId,
            redirectUrl: $redirectUrl,
            providerCustomerId: $customerId,
            collectionMode: $this->collectionMode($redirectUrl, $paymentMethod),
            providerPaymentMethod: $paymentMethod,
        );
    }

    public function downloadInvoice(string $invoiceId): InvoiceDocument
    {
        $doc = $this->client->exportInvoice($invoiceId);

        return new InvoiceDocument(
            filename: sprintf('invoice-%s.pdf', $invoiceId),
            contentType: $doc['contentType'],
            content: $doc['content'],
        );
    }

    public function sendPaymentReminder(string $providerPaymentId): void
    {
        $this->client->sendPaymentReminder($providerPaymentId);
    }

    public function requestPaymentMethodChange(
        string $providerCustomerId,
        PaymentMethodChangeDelivery $delivery = PaymentMethodChangeDelivery::EMAIL,
    ): PaymentMethodChangeResult {
        $url = $this->client->requestPaymentMethodChange($providerCustomerId, strtoupper($delivery->value));

        return new PaymentMethodChangeResult($delivery, $url);
    }

    public function getCustomerPaymentMethods(string $providerCustomerId): array
    {
        return $this->client->getCustomerPaymentMethods($providerCustomerId);
    }

    /**
     * Ensure the Payactive customer exists with address + vatId (ZUGFeRD needs
     * them). Creates a new customer, or updates an existing one to backfill the
     * billing details.
     */
    private function ensureInvoiceCustomer(CreateInvoiceRequest $request): string
    {
        $customerPayload = [
            'emailAddress' => $request->customerEmail,
            'firstName' => $request->customerFirstName,
            'lastName' => $request->customerLastName,
            'type' => $request->customerType,
            'paymentMethod' => $this->customerPaymentMethod(),
            'invitationType' => 'LINK',
            'externalRef' => $request->customerExternalReference,
            'companyName' => $request->companyName,
            'vatId' => $request->vatId,
            'address' => [
                'line' => $request->address->line,
                'zipCode' => $request->address->zipCode,
                'city' => $request->address->city,
                'country' => $request->address->country,
            ],
        ];
        $customerPayload = array_filter($customerPayload, static fn ($v) => null !== $v);

        $existing = null;
        if (null !== $request->providerCustomerId && '' !== $request->providerCustomerId) {
            $existing = $this->client->getCustomer($request->providerCustomerId);
            $existing['id'] = $request->providerCustomerId;
        } else {
            $existing = $this->client->findCustomerByEmail($request->customerEmail);
        }
        $existingId = is_array($existing) && is_string($existing['id'] ?? null) ? $existing['id'] : null;
        if (null !== $existingId) {
            $customerPayload['paymentMethod'] = $this->existingCustomerPaymentMethod($existing, $existingId);
            $this->client->updateCustomer($existingId, $customerPayload);

            return $existingId;
        }

        return $this->client->createCustomer($customerPayload);
    }

    /**
     * Keep the payment method selected in Payactive (e.g. MANUAL_PAYMENT or
     * DIRECT_DEBIT). Updating address/VAT data must not reset an invoice-first
     * customer back to the configured onboarding default.
     *
     * @param array<string, mixed> $existing
     */
    private function existingCustomerPaymentMethod(array $existing, string $customerId): string
    {
        $method = $existing['paymentMethod'] ?? null;
        if (!is_string($method) || '' === $method) {
            $fresh = $this->client->getCustomer($customerId);
            $method = $fresh['paymentMethod'] ?? null;
        }

        return is_string($method) && '' !== $method ? $method : $this->customerPaymentMethod();
    }

    /** @return array<string, mixed> */
    private function mapPosition(InvoicePosition $position): array
    {
        $mapped = [
            'description' => $position->description,
            'quantity' => $position->quantity,
            'price' => $position->unitPrice,
        ];
        if (null !== $position->taxRatePercent) {
            $mapped['taxRate'] = [
                'rate' => $position->taxRatePercent,
                'description' => $this->taxDescription($position->taxRatePercent, null),
            ];
        }

        return $mapped;
    }

    /** A non-empty human label for a tax rate (Payactive renders it on the ZUGFeRD PDF). */
    private function taxDescription(float $rate, ?string $note): string
    {
        if (null !== $note && '' !== $note) {
            return $note;
        }

        return 0.0 === $rate ? 'Steuerfrei' : rtrim(rtrim(sprintf('%.2f', $rate), '0'), '.').'%';
    }

    /**
     * The single, concrete payment method stored on the customer. Payactive does
     * not accept CUSTOMERS_CHOICE here, so we always use the configured concrete
     * method (default ONLINE_PAYMENT). This is what an invoice-first payment will
     * use; payment-first overrides it per request via the paymentMethod array.
     */
    private function customerPaymentMethod(): string
    {
        return $this->customerPaymentMethod;
    }

    /** @return list<string> */
    private function invoicePaymentMethods(): array
    {
        $methods = array_map(
            static fn (mixed $method): string => strtoupper(trim((string) $method)),
            $this->invoicePaymentMethods,
        );

        return array_values(array_unique(array_filter($methods, static fn (string $method): bool => '' !== $method)));
    }

    public function fetchPaymentStatus(string $providerPaymentId): PaymentStatusSnapshot
    {
        $data = $this->client->getPayment($providerPaymentId);
        $rawState = isset($data['state']) && is_string($data['state']) ? $data['state'] : '';

        return new PaymentStatusSnapshot(
            status: $this->mapState($rawState),
            raw: $data,
            collectionMode: $this->collectionMode(null, $this->paymentMethodFromPayload($data)),
            providerPaymentMethod: $this->paymentMethodFromPayload($data),
            amount: is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null,
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : null,
        );
    }

    /** Map Payactive payment state to our normalized PaymentStatus enum. */
    private function mapState(string $state): PaymentStatus
    {
        return match ($state) {
            // COMPLETED = the customer finished the (online) payment, but it is
            // not yet matched/verified on the account → NOT settled yet. Only
            // VERIFIED is a real settlement (this is also exactly when Payactive
            // fires the payment.settled webhook). Keeps polling consistent with
            // the webhook semantics.
            'CREATING', 'PENDING', 'MANUAL', 'COMPLETED' => PaymentStatus::INITIATED,
            'VERIFIED' => PaymentStatus::SETTLED,
            'ABORTED', 'ERROR' => PaymentStatus::FAILED,
            'CANCELLED' => PaymentStatus::CANCELLED,
            'REFUND_IN_PROGRESS' => PaymentStatus::REFUND_PENDING,
            'REFUND_COMPLETED' => PaymentStatus::REFUNDED,
            'CHARGED_BACK' => PaymentStatus::CHARGED_BACK,
            '' => throw new PaymentProviderException('Payactive: payment response missing "state".'),
            default => PaymentStatus::INITIATED,
        };
    }

    /** @param array<string, mixed> $payload */
    private function paymentMethodFromPayload(array $payload): ?string
    {
        $method = $payload['paymentMethod'] ?? null;
        if (is_array($method)) {
            $method = $method['type'] ?? $method['name'] ?? null;
        }

        return is_string($method) && '' !== $method ? $method : null;
    }

    private function collectionMode(?string $redirectUrl, ?string $paymentMethod): CollectionMode
    {
        if (null !== $redirectUrl && '' !== $redirectUrl) {
            return CollectionMode::HOSTED_ACTION;
        }

        return match (strtoupper((string) $paymentMethod)) {
            'ONLINE_PAYMENT', 'PAPERLESS', 'CUSTOMERS_CHOICE' => CollectionMode::HOSTED_ACTION,
            'DIRECT_DEBIT', 'SEPA_DIRECT_DEBIT', 'CREDIT_CARD', 'CARD' => CollectionMode::AUTOMATIC,
            'MANUAL_PAYMENT', 'BANK_TRANSFER', 'TRANSFER' => CollectionMode::OFFLINE_TRANSFER,
            default => CollectionMode::UNKNOWN,
        };
    }
}
