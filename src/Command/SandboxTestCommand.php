<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Command;

use Fewohbee\PaymentCore\Entity\PaymentTransaction;
use Fewohbee\PaymentCore\Adapter\Payactive\PayactiveClient;
use Fewohbee\PaymentCore\Dto\BillingAddress;
use Fewohbee\PaymentCore\Dto\CreateInvoiceRequest;
use Fewohbee\PaymentCore\Dto\CreatePaymentRequest;
use Fewohbee\PaymentCore\Dto\InvoicePosition;
use Fewohbee\PaymentCore\Enum\PaymentIntent;
use Fewohbee\PaymentCore\Enum\PaymentKind;
use Fewohbee\PaymentCore\Service\InvoiceService;
use Fewohbee\PaymentCore\Service\PaymentService;
use Fewohbee\PaymentCore\Repository\PaymentTransactionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual end-to-end sandbox test driver for the active payment provider
 * (currently: Payactive). Not part of the production feature surface — kept
 * around for ad-hoc verification against the sandbox. Safe to delete once
 * the integration is in production.
 *
 * Subcommands:
 *   initiate           Create a fresh customer + payment, print the hosted URL.
 *                      Pass --kind=deposit|balance|full to classify the transaction.
 *   invoice            Create + finalize a ZUGFeRD invoice, print pay-link +
 *                      invoice number, download the PDF (decision-gate A0).
 *   deposit            Convenience demo for the deposit/balance flow: takes
 *                      --total and --percent, creates a DEPOSIT transaction
 *                      for the calculated share, prints the follow-up command
 *                      to later create the BALANCE transaction with the same
 *                      --reference.
 *   list <ref>         Show all transactions for a given externalReference
 *                      (grouped by kind/status — useful to inspect a booking).
 *   sync <id>          Pull current state from the provider, update DB, print transition.
 *   show <id>          Print the local row contents without hitting the API.
 *   methods            Probe Payactive for the account's available payment methods.
 *
 * Prerequisites (.env.local):
 *   PAYMENT_PROVIDER=payactive
 *   PAYACTIVE_API_KEY=<sandbox key>
 *   PAYACTIVE_API_BASE_URL=https://api.sandbox.payactive.app
 */
#[AsCommand(
    name: 'payment:sandbox-test',
    description: 'Manual sandbox test driver — initiate / sync / show payments against the active provider.'
)]
class SandboxTestCommand extends Command
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentTransactionRepository $transactionRepository,
        private readonly PayactiveClient $payactiveClient,
        private readonly InvoiceService $invoiceService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'initiate | deposit | invoice | list | sync | show | methods')
            ->addArgument('id', InputArgument::OPTIONAL, 'Transaction id (sync/show) or externalReference (list)')
            ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Amount in EUR', '1.23')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Customer email (defaults to a random sandbox address)')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'Customer first name', 'Sandbox')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Customer last name', 'Tester')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'External reference (defaults to a random sandbox-<hex>)')
            ->addOption('purpose', null, InputOption::VALUE_REQUIRED, 'Payment purpose (defaults to "Sandbox-Buchung <reference>")')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Transaction kind: deposit | balance | full')
            ->addOption('total', null, InputOption::VALUE_REQUIRED, 'Total booking amount in EUR (for "deposit")', '200.00')
            ->addOption('percent', null, InputOption::VALUE_REQUIRED, 'Deposit percentage of total (for "deposit")', '30')
            // invoice options
            ->addOption('tax-rate', null, InputOption::VALUE_REQUIRED, 'Tax rate percent for invoice positions (e.g. 19 or 0)', '19')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Company name (→ ORGANIZATION customer)')
            ->addOption('vat-id', null, InputOption::VALUE_REQUIRED, 'Customer VAT id (USt-ID)')
            ->addOption('line', null, InputOption::VALUE_REQUIRED, 'Billing address line', 'Teststraße 1')
            ->addOption('zip', null, InputOption::VALUE_REQUIRED, 'Billing zip code', '01099')
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'Billing city', 'Dresden')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Billing country (ISO-2)', 'DE')
            ->addOption('service-start', null, InputOption::VALUE_REQUIRED, 'Service period start (Y-m-d); defaults to today')
            ->addOption('service-end', null, InputOption::VALUE_REQUIRED, 'Service period end (Y-m-d); set for a period invoice')
            ->addOption('gross', null, InputOption::VALUE_NONE, 'Treat the amount as gross (incl. tax). Default: net (matches the working portal-UI invoice).')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Where to write the downloaded invoice PDF', sys_get_temp_dir().'/payactive-invoice.pdf');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        return match ($action) {
            'initiate' => $this->initiate($input, $io),
            'deposit' => $this->deposit($input, $io),
            'invoice' => $this->invoice($input, $io),
            'list' => $this->list($input, $io),
            'sync' => $this->sync($input, $io),
            'show' => $this->show($input, $io),
            'methods' => $this->methods($io),
            default => $this->fail($io, sprintf('Unknown action "%s". Use initiate | deposit | invoice | list | sync | show | methods.', $action)),
        };
    }

    /**
     * Decision-gate driver (plan Teil A0): create + finalize a ZUGFeRD invoice,
     * print invoice number / pay-link / available methods, and download the PDF.
     * Answers: does invoice-first preserve payment-method choice, is there a
     * pay-link, does finalize/export work?
     */
    private function invoice(InputInterface $input, SymfonyStyle $io): int
    {
        $amount = (float) $input->getOption('amount');
        $taxRate = (float) $input->getOption('tax-rate');
        $reference = $input->getOption('reference') ?? 'inv-sandbox-'.bin2hex(random_bytes(4));
        $email = $input->getOption('email') ?? ('sandbox-'.bin2hex(random_bytes(3)).'@example.com');
        $company = $input->getOption('company');
        $vatId = $input->getOption('vat-id');
        $serviceStart = $input->getOption('service-start');
        $serviceEnd = $input->getOption('service-end');

        $request = new CreateInvoiceRequest(
            externalReference: $reference,
            currency: 'EUR',
            purpose: $input->getOption('purpose') ?? ('Sandbox-Rechnung '.$reference),
            customerEmail: $email,
            customerFirstName: (string) $input->getOption('first-name'),
            customerLastName: (string) $input->getOption('last-name'),
            address: new BillingAddress(
                (string) $input->getOption('line'),
                (string) $input->getOption('zip'),
                (string) $input->getOption('city'),
                (string) $input->getOption('country'),
            ),
            positions: [
                new InvoicePosition('Sandbox-Posten', 1.0, $amount, $taxRate),
            ],
            companyName: $company,
            vatId: $vatId,
            customerType: null !== $company ? 'ORGANIZATION' : 'PERSON',
            grossInvoice: (bool) $input->getOption('gross'),
            defaultTaxRatePercent: $taxRate,
            paymentTermInDays: 14,
            servicePeriodStart: null !== $serviceStart ? new \DateTimeImmutable((string) $serviceStart) : null,
            servicePeriodEnd: null !== $serviceEnd ? new \DateTimeImmutable((string) $serviceEnd) : null,
        );

        $io->section('Creating + finalizing invoice via active provider…');
        $initiation = $this->invoiceService->createInvoice($request);

        $io->definitionList(
            ['invoiceId' => $initiation->invoiceId],
            ['invoiceNumber' => $initiation->invoiceNumber ?? '(none)'],
            ['providerPaymentId' => $initiation->providerPaymentId ?? '(none → polling will not work)'],
            ['pay-link' => $initiation->redirectUrl ?? '(none)'],
            ['externalReference' => $reference],
            ['amount' => sprintf('%.2f EUR (tax %.0f%%)', $amount, $taxRate)],
        );

        // Download the finalized PDF to inspect the ZUGFeRD output.
        try {
            $doc = $this->invoiceService->downloadInvoice($initiation->invoiceId);
            $out = (string) $input->getOption('out');
            file_put_contents($out, $doc->content);
            $io->success(sprintf('Invoice PDF (%s, %d bytes) written to %s', $doc->contentType, strlen($doc->content), $out));
        } catch (\Throwable $e) {
            $io->warning('PDF download failed: '.$e->getMessage());
        }

        $io->note([
            'Decision-gate checks (plan A0):',
            '1. Open the pay-link — which payment methods are offered? (CUSTOMERS_CHOICE → multiple?)',
            '2. Pay-link present above?',
            '3. Did the customer receive a Payactive e-mail (→ double-send risk)?',
            '4. §19 note / 0% correct on the PDF when --tax-rate=0?',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Probe the undocumented `payment-settings/available-payment-methods` endpoint.
     * Tries multiple plausible paths because the developer was unsure whether it's
     * portal-only or accessible via the public API key.
     */
    private function methods(SymfonyStyle $io): int
    {
        $candidates = [
            'payment-settings/available-payment-methods',
        ];

        $io->section('Probing available-payment-methods endpoint variants…');

        $anySuccess = false;
        foreach ($candidates as $path) {
            $io->writeln(sprintf('GET /%s', $path));
            try {
                $data = $this->payactiveClient->get($path);
                $anySuccess = true;
                $io->success(sprintf('OK — /%s responded with:', $path));
                $io->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $io->writeln(sprintf('  → %s', $e->getMessage()));
            }
        }

        if (!$anySuccess) {
            $io->warning([
                'None of the probed endpoints responded successfully.',
                'The endpoint is likely portal-only (Keycloak/Bearer auth) and not reachable with the api_key header.',
                'Ask the Payactive developer to confirm the canonical public path.',
            ]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function initiate(InputInterface $input, SymfonyStyle $io): int
    {
        $amount = (float) $input->getOption('amount');
        $reference = $input->getOption('reference') ?? 'sandbox-'.bin2hex(random_bytes(4));
        $email = $input->getOption('email') ?? ('sandbox-'.bin2hex(random_bytes(3)).'@example.com');
        $purpose = $input->getOption('purpose') ?? ('Sandbox-Buchung '.$reference);
        $kind = $this->parseKindOption($input->getOption('kind'));

        return $this->createAndReport($io, $reference, $email, $purpose, $amount, $kind, $input);
    }

    /**
     * High-level demo of the deposit/balance flow:
     *
     *   Booking total = 200 EUR, deposit = 30%
     *     → create transaction A (kind=DEPOSIT, amount=60.00, reference=booking-X)
     *     → guest pays the 60 EUR via the email link
     *     → later (e.g. on arrival), the hotelier triggers the balance
     *       with the SAME reference: a second transaction (kind=BALANCE,
     *       amount=140.00) is created. Both rows are findable via
     *       `payment:sandbox-test list booking-X`.
     */
    private function deposit(InputInterface $input, SymfonyStyle $io): int
    {
        $total = (float) $input->getOption('total');
        $percent = (float) $input->getOption('percent');
        if ($total <= 0.0 || $percent <= 0.0 || $percent > 100.0) {
            return $this->fail($io, 'Provide --total > 0 and 0 < --percent <= 100.');
        }

        $depositAmount = round($total * $percent / 100.0, 2);
        $balanceAmount = round($total - $depositAmount, 2);

        $reference = $input->getOption('reference') ?? 'booking-'.bin2hex(random_bytes(4));
        $email = $input->getOption('email') ?? ('sandbox-'.bin2hex(random_bytes(3)).'@example.com');
        $purpose = $input->getOption('purpose') ?? sprintf('Anzahlung Buchung %s', $reference);

        $io->section(sprintf(
            'Deposit demo — total %.2f EUR, %.0f%% deposit = %.2f EUR (balance %.2f EUR)',
            $total,
            $percent,
            $depositAmount,
            $balanceAmount,
        ));

        $result = $this->createAndReport($io, $reference, $email, $purpose, $depositAmount, PaymentKind::DEPOSIT, $input);
        if (Command::SUCCESS !== $result) {
            return $result;
        }

        $io->note([
            'When the deposit settles and the rest is due, create the balance with the same --reference:',
            sprintf(
                'php bin/console payment:sandbox-test initiate --kind=balance --amount=%.2f --reference=%s --email=%s',
                $balanceAmount,
                $reference,
                $email,
            ),
            sprintf('Then inspect both rows:  php bin/console payment:sandbox-test list %s', $reference),
        ]);

        return Command::SUCCESS;
    }

    private function list(InputInterface $input, SymfonyStyle $io): int
    {
        $reference = (string) ($input->getArgument('id') ?? '');
        if ('' === $reference) {
            return $this->fail($io, 'Usage: payment:sandbox-test list <externalReference>');
        }

        $rows = $this->transactionRepository->findByExternalReference($reference);
        if ([] === $rows) {
            $io->warning(sprintf('No transactions found for externalReference "%s".', $reference));

            return Command::SUCCESS;
        }

        $table = [];
        $sum = 0.0;
        foreach ($rows as $t) {
            $table[] = [
                $t->getId(),
                $t->getKind()?->value ?? '—',
                sprintf('%.2f %s', $t->getAmount(), $t->getCurrency()),
                $t->getStatus()->value,
                $t->getCreatedAt()->format('Y-m-d H:i'),
                $t->getProviderPaymentId(),
            ];
            $sum += $t->getAmount();
        }

        $io->table(['id', 'kind', 'amount', 'status', 'created', 'providerPaymentId'], $table);
        $io->writeln(sprintf('Total across %d transaction(s): <info>%.2f EUR</info>', count($rows), $sum));

        return Command::SUCCESS;
    }

    /**
     * Shared "create + print result" path used by `initiate` and `deposit`.
     */
    private function createAndReport(
        SymfonyStyle $io,
        string $reference,
        string $email,
        string $purpose,
        float $amount,
        ?PaymentKind $kind,
        InputInterface $input,
    ): int {
        $request = new CreatePaymentRequest(
            amount: $amount,
            currency: 'EUR',
            purpose: $purpose,
            customerEmail: $email,
            customerFirstName: (string) $input->getOption('first-name'),
            customerLastName: (string) $input->getOption('last-name'),
            externalReference: $reference,
            intent: PaymentIntent::PAYMENT,
            kind: $kind,
        );

        $io->writeln('Initiating payment via active provider…');
        $initiation = $this->paymentService->initiate($request);

        $transaction = $this->transactionRepository->findOneByProviderAndProviderPaymentId(
            providerId: 'payactive',
            providerPaymentId: $initiation->providerPaymentId,
        );

        $io->definitionList(
            ['transactionId' => (string) ($transaction?->getId() ?? '?')],
            ['kind' => $kind?->value ?? '(none)'],
            ['providerPaymentId' => $initiation->providerPaymentId],
            ['externalReference' => $reference],
            ['amount' => sprintf('%.2f EUR', $amount)],
            ['redirect URL' => $initiation->redirectUrl ?? '(none returned)'],
        );

        if (null !== $initiation->redirectUrl) {
            $io->success([
                'Hosted-page URL printed above — Payactive also mails the link to the guest.',
                sprintf('Sync status later with:  php bin/console payment:sandbox-test sync %s', $transaction?->getId() ?? '<id>'),
            ]);
        } else {
            $io->warning('Provider did not return a hosted-page URL.');
        }

        return Command::SUCCESS;
    }

    private function parseKindOption(mixed $raw): ?PaymentKind
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $value = is_string($raw) ? strtolower($raw) : '';
        $kind = PaymentKind::tryFrom($value);
        if (null === $kind) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid --kind "%s". Allowed: %s.',
                $raw,
                implode(', ', array_map(static fn (PaymentKind $k) => $k->value, PaymentKind::cases())),
            ));
        }

        return $kind;
    }

    private function sync(InputInterface $input, SymfonyStyle $io): int
    {
        $id = (int) $input->getArgument('id');
        if ($id <= 0) {
            return $this->fail($io, 'Usage: payment:sandbox-test sync <transactionId>');
        }

        $current = $this->transactionRepository->find($id);
        if (!$current instanceof PaymentTransaction) {
            return $this->fail($io, sprintf('Transaction #%d not found.', $id));
        }

        $before = $current->getStatus()->value;
        $after = $this->paymentService->syncStatus($id)->value;

        $io->success(sprintf('Transaction #%d: %s → %s', $id, $before, $after));

        return Command::SUCCESS;
    }

    private function show(InputInterface $input, SymfonyStyle $io): int
    {
        $id = (int) $input->getArgument('id');
        if ($id <= 0) {
            return $this->fail($io, 'Usage: payment:sandbox-test show <transactionId>');
        }

        $t = $this->transactionRepository->find($id);
        if (!$t instanceof PaymentTransaction) {
            return $this->fail($io, sprintf('Transaction #%d not found.', $id));
        }

        $io->definitionList(
            ['id' => (string) $t->getId()],
            ['providerId' => $t->getProviderId()],
            ['providerPaymentId' => $t->getProviderPaymentId()],
            ['externalReference' => $t->getExternalReference()],
            ['amount' => sprintf('%.2f %s', $t->getAmount(), $t->getCurrency())],
            ['status' => $t->getStatus()->value],
            ['intent' => $t->getIntent()->value],
            ['kind' => $t->getKind()?->value ?? '(none)'],
            ['createdAt' => $t->getCreatedAt()->format('c')],
            ['updatedAt' => $t->getUpdatedAt()->format('c')],
        );

        return Command::SUCCESS;
    }

    private function fail(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::FAILURE;
    }
}
