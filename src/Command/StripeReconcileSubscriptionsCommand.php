<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\StripeSubscriptionSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stripe:reconcile-subscriptions',
    description: 'Compara Stripe con la BD y actualiza paidThroughAt / cancel_at_period_end (p. ej. webhooks perdidos)',
)]
final class StripeReconcileSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly StripeSubscriptionSyncService $subscriptionSyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user-id', null, InputOption::VALUE_OPTIONAL, 'Sincronizar solo este id de usuario');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getOption('user-id');

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        if ($userId !== null && $userId !== '') {
            $user = $this->userRepository->find((int) $userId);
            if ($user === null) {
                $io->error('Usuario no encontrado.');

                return Command::FAILURE;
            }
            if ($this->subscriptionSyncService->syncCustomerSubscriptionsFromStripe($user)) {
                ++$updated;
            } else {
                ++$skipped;
            }
            $io->success(sprintf('Hecho: actualizados=%d omitidos=%d', $updated, $skipped));

            return Command::SUCCESS;
        }

        foreach ($this->userRepository->iterateUsersWithStripeCustomer() as $user) {
            try {
                if ($this->subscriptionSyncService->syncCustomerSubscriptionsFromStripe($user)) {
                    ++$updated;
                } else {
                    ++$skipped;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning(sprintf('User %s: %s', (string) $user->getId(), $e->getMessage()));
            }
        }

        $io->success(sprintf('Reconciliación: actualizados=%d sin cambio o sin subs=%d errores=%d', $updated, $skipped, $failed));

        return Command::SUCCESS;
    }
}
