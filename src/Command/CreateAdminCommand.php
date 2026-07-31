<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ClientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Crea o promociona un usuario con ROLE_ADMIN (operador del panel).',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email del operador')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password en claro (se hashea)')
            ->addOption('promote-only', null, InputOption::VALUE_NONE, 'Solo añade ROLE_ADMIN a un usuario existente');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));
        $promoteOnly = (bool) $input->getOption('promote-only');
        $password = $input->getOption('password');

        $repo = $this->em->getRepository(User::class);
        /** @var User|null $user */
        $user = $repo->findOneBy(['email' => $email]);

        if ($user === null) {
            if ($promoteOnly) {
                $io->error(sprintf('No existe usuario con email %s.', $email));

                return Command::FAILURE;
            }
            if (!\is_string($password) || $password === '') {
                $io->error('Indica --password=... para crear el usuario.');

                return Command::FAILURE;
            }

            $user = new User();
            $user->setEmail($email);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setRoles([User::ROLE_USER, User::ROLE_ADMIN]);
            $user->setVerifiedEmail(true);

            $client = new ClientProfile();
            $client->setFullName('Admin Quira');
            $client->setUser($user);
            $user->setClientProfile($client);

            $this->em->persist($user);
            $this->em->persist($client);
            $this->em->flush();
            $io->success(sprintf('Usuario admin creado: %s', $email));

            return Command::SUCCESS;
        }

        $roles = array_values(array_unique([...$user->getRoles(), User::ROLE_ADMIN]));
        $user->setRoles($roles);

        if (\is_string($password) && $password !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }

        $this->em->flush();
        $io->success(sprintf(
            'Usuario %s con ROLE_ADMIN. Roles efectivos: %s',
            $email,
            implode(', ', $user->getRoles())
        ));

        return Command::SUCCESS;
    }
}
