<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Adapter\Payactive;

use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\CreatePaymentRequest;
use Fewohbee\PaymentCore\Dto\InvoiceDocument;
use Fewohbee\PaymentCore\Dto\InvoiceInitiation;
use Fewohbee\PaymentCore\Dto\InvoicePosition;
use Fewohbee\PaymentCore\Dto\PaymentInitiation;
use Fewohbee\PaymentCore\Dto\PaymentStatusSnapshot;
use Fewohbee\PaymentCore\Enum\PaymentStatus;
use Fewohbee\PaymentCore\Enum\ProviderCapability;
use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use Fewohbee\PaymentCore\Provider\InvoiceProviderInterface;
use Fewohbee\PaymentCore\Provider\PaymentProviderInterface;

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
class PayactiveProvider implements PaymentProviderInterface, InvoiceProviderInterface
{
    public const ID = 'payactive';

    /**
     * @param list<string> $paymentMethods Methods offered to the customer.
     *   Default CUSTOMERS_CHOICE lets the payer pick among the account's enabled
     *   methods (online transfer / direct debit / manual) — also for invoices.
     */
    public function __construct(
        private readonly PayactiveClient $client,
        private readonly array $paymentMethods = ['CUSTOMERS_CHOICE'],
        private readonly ?string $creditorBankAccountId = null,
    ) {
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function supports(ProviderCapability $capability): bool
    {
        return match ($capability) {
            ProviderCapability::ONLINE_PAYMENT => true,
            ProviderCapability::INVOICE => null !== $this->creditorBankAccountId && '' !== $this->creditorBankAccountId,
            // DIRECT_DEBIT is technically supported by Payactive but requires the
            // SEPA mandate flow which is not implemented here yet.
            // CARD_PREAUTH is not visible in the Payactive sandbox UI — pending clarification.
            // REFUND not yet wired up.
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

        $payload = [
            'customerId' => $customerId,
            'creditorBankAccountId' => $this->creditorBankAccountId,
            'positions' => array_map($this->mapPosition(...), $request->positions),
            'grossInvoice' => $request->grossInvoice,
            'reverseCharge' => $request->reverseCharge,
            'metadata' => $this->invoiceMetadata($request),
        ];
        if (null !== $request->defaultTaxRatePercent) {
            $payload['defaultTaxRate'] = ['rate' => $request->defaultTaxRatePercent, 'description' => $request->taxExemptNote ?? ''];
        }
        if (null !== $request->paymentTermInDays) {
            $payload['paymentTermInDays'] = $request->paymentTermInDays;
        }
        if (null !== $request->servicePeriodStart) {
            $payload['servicePeriodStart'] = $request->servicePeriodStart->format('Y-m-d');
        }
        if (null !== $request->servicePeriodEnd) {
            $payload['servicePeriodEnd'] = $request->servicePeriodEnd->format('Y-m-d');
        }

        $invoiceId = $this->client->createInvoice($payload)['id'];
        $finalized = $this->client->finalizeInvoice($invoiceId);

        $invoiceNumber = isset($finalized['invoiceNumber']) && is_string($finalized['invoiceNumber'])
            ? $finalized['invoiceNumber'] : null;
        $paymentId = isset($finalized['paymentId']) && is_string($finalized['paymentId'])
            ? $finalized['paymentId'] : null;
        $redirectUrl = null !== $paymentId ? $this->client->getPaymentLink($paymentId) : null;

        return new InvoiceInitiation(
            invoiceId: $invoiceId,
            invoiceNumber: $invoiceNumber,
            providerPaymentId: $paymentId,
            redirectUrl: $redirectUrl,
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
            'externalRef' => $request->externalReference,
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

        $existingId = $this->client->findCustomerIdByEmail($request->customerEmail);
        if (null !== $existingId) {
            $this->client->updateCustomer($existingId, $customerPayload);

            return $existingId;
        }

        return $this->client->createCustomer($customerPayload);
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
            $mapped['taxRate'] = ['rate' => $position->taxRatePercent, 'description' => ''];
        }

        return $mapped;
    }

    /** @return array<int, array{key: string, value: string}> */
    private function invoiceMetadata(CreateInvoiceRequest $request): array
    {
        $meta = [['key' => 'externalReference', 'value' => $request->externalReference]];
        if (null !== $request->taxExemptNote) {
            $meta[] = ['key' => 'taxExemptNote', 'value' => $request->taxExemptNote];
        }

        return $meta;
    }

    /**
     * The single payment method stored on the customer. CUSTOMERS_CHOICE if the
     * configured set allows the payer to choose, otherwise the single configured
     * method.
     */
    private function customerPaymentMethod(): string
    {
        if (in_array('CUSTOMERS_CHOICE', $this->paymentMethods, true)) {
            return 'CUSTOMERS_CHOICE';
        }

        return 1 === count($this->paymentMethods) ? $this->paymentMethods[0] : 'CUSTOMERS_CHOICE';
    }

    public function fetchPaymentStatus(string $providerPaymentId): PaymentStatusSnapshot
    {
        $data = $this->client->getPayment($providerPaymentId);
        $rawState = isset($data['state']) && is_string($data['state']) ? $data['state'] : '';

        return new PaymentStatusSnapshot(
            status: $this->mapState($rawState),
            raw: $data,
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
            'REFUND_IN_PROGRESS', 'REFUND_COMPLETED' => PaymentStatus::REFUNDED,
            'CHARGED_BACK' => PaymentStatus::FAILED,
            '' => throw new PaymentProviderException('Payactive: payment response missing "state".'),
            default => PaymentStatus::INITIATED,
        };
    }
}
