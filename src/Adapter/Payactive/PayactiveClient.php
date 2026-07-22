<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Adapter\Payactive;

use Fewohbee\PaymentCore\Exception\PaymentProviderException;
use Fewohbee\PaymentCore\Exception\PaymentProviderTransportException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin HTTP wrapper around the Payactive REST API.
 * Intentionally minimal — only exposes the calls the provider adapter needs.
 *
 * Docs: https://apidocs.payactive.eu/
 */
class PayactiveClient
{
    private const DEFAULT_TIMEOUT = 10;

    private LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create a customer. Returns the customer id.
     *
     * @param array<string, mixed> $payload
     */
    public function createCustomer(array $payload): string
    {
        $data = $this->requestJson('POST', '/customers', $payload);
        $id = $data['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new PaymentProviderException('Payactive: createCustomer response missing "id".');
        }

        return $id;
    }

    /**
     * Create a payment. Returns the payment id.
     *
     * @param array<string, mixed> $payload
     */
    public function createPayment(array $payload): string
    {
        $data = $this->requestJson('POST', '/payments', $payload);
        $id = $data['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new PaymentProviderException('Payactive: createPayment response missing "id".');
        }

        return $id;
    }

    public function getPaymentLink(string $paymentId): ?string
    {
        try {
            $data = $this->requestJson('GET', '/payments/'.rawurlencode($paymentId).'/payment-link');
        } catch (PaymentProviderException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }

            throw $e;
        }
        $link = $data['paymentLink'] ?? null;

        return is_string($link) && '' !== $link ? $link : null;
    }

    /** @return array<string, mixed> */
    public function getPayment(string $paymentId): array
    {
        return $this->requestJson('GET', '/payments/'.rawurlencode($paymentId));
    }

    public function sendPaymentReminder(string $paymentId): void
    {
        $this->requestJson('POST', '/payments/'.rawurlencode($paymentId).'/actions/send-payment-reminder');
    }

    /**
     * Raw GET — useful for probing endpoints not (yet) wrapped by a dedicated method.
     *
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        return $this->requestJson('GET', '/'.ltrim($path, '/'));
    }

    /**
     * Returns the customer id whose `emailAddress` exactly matches the given email,
     * or null if no such customer exists yet.
     */
    public function findCustomerIdByEmail(string $email): ?string
    {
        $customer = $this->findCustomerByEmail($email);
        $id = $customer['id'] ?? null;

        return is_string($id) && '' !== $id ? $id : null;
    }

    /**
     * Returns the customer whose `emailAddress` exactly matches the given email,
     * or null if no such customer exists yet.
     *
     * @return array<string, mixed>|null
     */
    public function findCustomerByEmail(string $email): ?array
    {
        $data = $this->requestJson('GET', '/customers/search?'.http_build_query([
            'search' => $email,
            'size' => 25,
        ]));

        $customers = $data['_embedded']['customers'] ?? [];
        if (!is_array($customers)) {
            return null;
        }

        foreach ($customers as $customer) {
            if (!is_array($customer)) {
                continue;
            }
            $candidateEmail = $customer['emailAddress'] ?? null;
            if (is_string($candidateEmail) && 0 === strcasecmp($candidateEmail, $email)) {
                return $customer;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function getCustomer(string $customerId): array
    {
        return $this->requestJson('GET', '/customers/'.rawurlencode($customerId));
    }

    /**
     * Fetch the account's enabled payment methods from the (undocumented but stable)
     * payment-settings endpoint. Returns only methods where `available === true`.
     *
     * @return list<string> e.g. ["ONLINE_PAYMENT", "DIRECT_DEBIT", "MANUAL_PAYMENT"]
     */
    public function getAvailablePaymentMethods(): array
    {
        $data = $this->requestJson('GET', '/payment-settings/available-payment-methods');

        $methods = [];
        // Endpoint returns a bare JSON array, which Symfony's toArray exposes as
        // a list at the top level (numeric keys).
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $available = $entry['available'] ?? false;
            $name = $entry['paymentMethod'] ?? null;
            if (true === $available && is_string($name) && '' !== $name) {
                $methods[] = $name;
            }
        }

        return $methods;
    }

    /**
     * Update a customer (e.g. to backfill address / vatId before invoicing).
     *
     * @param array<string, mixed> $payload
     */
    public function updateCustomer(string $customerId, array $payload): void
    {
        $this->requestJson('PUT', '/customers/'.rawurlencode($customerId), $payload);
    }

    /**
     * Ask Payactive to let the customer change their payment method. EMAIL
     * deliberately returns no reusable link; LINK/MANUAL may return one.
     */
    public function requestPaymentMethodChange(string $customerId, string $invitationType): ?string
    {
        $path = '/customers/'.rawurlencode($customerId).'/actions/change-payment-method?'.http_build_query([
            'invitationType' => $invitationType,
        ]);
        // The OpenAPI contract declares */* with a scalar string. Depending on
        // the Payactive deployment this is returned either as a JSON string or
        // as plain text, so this endpoint must explicitly support both forms.
        $value = $this->requestValue('PATCH', $path, acceptPlainText: true);

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    /** @return list<string> */
    public function getCustomerPaymentMethods(string $customerId): array
    {
        $data = $this->requestJson('GET', '/customers/'.rawurlencode($customerId).'/payment-methods');

        return array_values(array_filter($data, static fn (mixed $method): bool => is_string($method) && '' !== $method));
    }

    /**
     * Create an invoice (DRAFT). Returns the full invoice response.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload): array
    {
        // Logged so the exact request can be diffed against the portal UI / handed
        // to Payactive support when finalize misbehaves.
        $this->logger->info('Payactive createInvoice payload', ['payload' => $payload]);
        $data = $this->requestJson('POST', '/invoices', $payload);
        if (!isset($data['id']) || !is_string($data['id']) || '' === $data['id']) {
            throw new PaymentProviderException('Payactive: createInvoice response missing "id".');
        }

        return $data;
    }

    /**
     * Recover a possibly ambiguous invoice creation by exact metadata match.
     * Payactive has no documented idempotency header for POST /invoices.
     *
     * @return array<string, mixed>|null
     */
    public function findInvoiceByMetadata(string $key, string $value): ?array
    {
        $data = $this->requestJson('GET', '/invoices?'.http_build_query([
            'filters' => ['search' => $value],
        ]));
        $invoices = $data['_embedded']['invoices'] ?? [];
        if (!is_array($invoices)) {
            return null;
        }

        foreach ($invoices as $invoice) {
            if (!is_array($invoice) || !is_array($invoice['metadata'] ?? null)) {
                continue;
            }
            foreach ($invoice['metadata'] as $metadata) {
                if (is_array($metadata) && ($metadata['key'] ?? null) === $key
                    && (string) ($metadata['value'] ?? '') === $value) {
                    return $invoice;
                }
            }
        }

        return null;
    }

    /**
     * Finalize an invoice (DRAFT → OPEN); returns the finalized invoice
     * (with invoiceNumber + paymentId).
     *
     * @return array<string, mixed>
     */
    public function finalizeInvoice(string $invoiceId): array
    {
        return $this->requestJson('POST', '/invoices/'.rawurlencode($invoiceId).'/actions/finalize');
    }

    /** @return array<string, mixed> */
    public function getInvoice(string $invoiceId): array
    {
        return $this->requestJson('GET', '/invoices/'.rawurlencode($invoiceId));
    }

    /**
     * Download a finalized invoice document. Returns raw bytes + content type.
     *
     * @return array{content: string, contentType: string}
     */
    public function exportInvoice(string $invoiceId, string $exportType = 'pdf'): array
    {
        return $this->requestBinary(
            'GET',
            '/invoices/'.rawurlencode($invoiceId).'/actions/export/'.rawurlencode($exportType)
        );
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, ?array $body = null): array
    {
        $value = $this->requestValue($method, $path, $body);
        if (!is_array($value)) {
            throw new PaymentProviderException(sprintf('Payactive: expected JSON object or array on %s %s.', $method, $path));
        }

        return $value;
    }

    /**
     * Payactive has successful JSON endpoints returning a scalar string.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>|string|int|float|bool|null
     */
    private function requestValue(
        string $method,
        string $path,
        ?array $body = null,
        bool $acceptPlainText = false,
    ): array|string|int|float|bool|null
    {
        if ('' === $this->apiKey) {
            throw new PaymentProviderException('Payactive: PAYACTIVE_API_KEY is not configured.');
        }

        $options = [
            'headers' => [
                'api_key' => $this->apiKey,
                'Accept' => 'application/json',
            ],
            'timeout' => self::DEFAULT_TIMEOUT,
        ];
        if (null !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            $this->logger->error('Payactive request transport error', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentProviderTransportException(sprintf('Payactive: transport error on %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            $bodyText = $this->safeBody($response);
            $this->logger->warning('Payactive HTTP error', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'body' => $bodyText,
            ]);
            throw new PaymentProviderException(sprintf('Payactive: HTTP %d on %s %s. Body: %s', $status, $method, $path, $bodyText));
        }

        try {
            $content = trim($response->getContent(false));
        } catch (ExceptionInterface $e) {
            throw new PaymentProviderTransportException(sprintf('Payactive: transport error while reading %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        // A successful PUT/PATCH/DELETE may return an empty body (204/200) —
        // not an error, just nothing to decode.
        if ('' === $content) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($acceptPlainText) {
                return $content;
            }

            throw new PaymentProviderException(sprintf('Payactive: invalid JSON on %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        return $data;
    }

    /**
     * Binary GET (e.g. invoice PDF export). Same auth/error handling as
     * requestJson but returns the raw body + content type.
     *
     * @return array{content: string, contentType: string}
     */
    private function requestBinary(string $method, string $path): array
    {
        if ('' === $this->apiKey) {
            throw new PaymentProviderException('Payactive: PAYACTIVE_API_KEY is not configured.');
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, [
                'headers' => ['api_key' => $this->apiKey, 'Accept' => 'application/pdf'],
                'timeout' => self::DEFAULT_TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new PaymentProviderException(sprintf('Payactive: HTTP %d on %s %s.', $status, $method, $path));
            }
            $content = $response->getContent();
            $contentType = $response->getHeaders()['content-type'][0] ?? 'application/octet-stream';
        } catch (ExceptionInterface $e) {
            throw new PaymentProviderTransportException(sprintf('Payactive: transport error on %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        if (str_starts_with(ltrim($content), '{')) {
            $decoded = json_decode($content, true);
            $base64 = is_array($decoded) ? ($decoded['content'] ?? null) : null;
            if (is_string($base64) && '' !== $base64) {
                $binary = base64_decode($base64, true);
                if (false === $binary) {
                    throw new PaymentProviderException(sprintf('Payactive: invalid base64 content on %s %s.', $method, $path));
                }

                return ['content' => $binary, 'contentType' => 'application/pdf'];
            }
        }

        return ['content' => $content, 'contentType' => $contentType];
    }

    private function safeBody(ResponseInterface $response): string
    {
        try {
            return mb_substr($response->getContent(false), 0, 500);
        } catch (ExceptionInterface) {
            return '';
        }
    }
}
