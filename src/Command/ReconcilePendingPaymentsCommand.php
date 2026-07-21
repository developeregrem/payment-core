<?php

declare(strict_types=1);

namespace Fewohbee\PaymentCore\Command;

use Fewohbee\PaymentCore\Service\PaymentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'payment:reconcile-due',
    description: 'Poll all due payment transactions, including scheduled settled-payment reversal audits.',
    aliases: ['payment:reconcile-pending'],
)]
class ReconcilePendingPaymentsCommand extends Command
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of transactions to process in this run', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        $webhooks = $this->paymentService->processPendingWebhooks($limit);
        if ($webhooks > 0) {
            $io->writeln(sprintf('Applied %d queued webhook event(s).', $webhooks));
        }

        $pending = $this->paymentService->findPending($limit);
        if ([] === $pending) {
            $io->success('No pending payment transactions.');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Processing %d pending transaction(s)…', count($pending)));

        $changed = 0;
        $failed = 0;
        foreach ($pending as $transaction) {
            $before = $transaction->getStatus();
            try {
                $result = $this->paymentService->syncTransactionResult($transaction);
                $after = $result->status;
                if (!$result->successful) {
                    ++$failed;
                    $io->warning(sprintf(
                        'Transaction #%d (%s/%s): %s',
                        $transaction->getId(),
                        $transaction->getProviderId(),
                        $transaction->getProviderPaymentId(),
                        $result->error ?? 'provider synchronization failed',
                    ));
                    continue;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning(sprintf(
                    'Transaction #%d (%s/%s): %s',
                    $transaction->getId(),
                    $transaction->getProviderId(),
                    $transaction->getProviderPaymentId(),
                    $e->getMessage(),
                ));
                continue;
            }

            if ($after !== $before) {
                ++$changed;
                $io->writeln(sprintf(
                    'Transaction #%d: %s → %s',
                    $transaction->getId(),
                    $before->value,
                    $after->value,
                ));
            }
        }

        if ($failed > 0) {
            $io->error(sprintf('Done with provider errors. %d changed, %d failed and were rescheduled.', $changed, $failed));

            return Command::FAILURE;
        }

        $io->success(sprintf('Done. %d transaction(s) changed state.', $changed));

        return Command::SUCCESS;
    }
}
