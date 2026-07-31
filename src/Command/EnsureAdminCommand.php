<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ClientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One-off (railway run / consola). NO ejecutar en boot HTTP ni por request.
 *
 * Lee ADMIN_EMAIL y ADMIN_PASSWORD del entorno (nunca hardcodeados).
 *
 * Al crear:
 * - roles: ROLE_ADMIN + ROLE_CLIENT (+ ROLE_USER vía getRoles())
 * - ClientProfile mínimo (faceta cliente)
 * - verifiedEmail=true (login email/password usable; el producto no exige email verificado
 *   en /login_check, pero lo dejamos listo)
 * - verifiedPhone: no se setea (sin teléfono). Crear solicitudes sí exige teléfono
 *   verificado; el panel admin no lo necesita.
 */
#[AsCommand(
    name: 'app:admin:ensure',
    description: 'Asegura un usuario ROLE_ADMIN desde ADMIN_EMAIL / ADMIN_PASSWORD (idempotente).',
)]
final class EnsureAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'reset-password',
            null,
            InputOption::VALUE_NONE,
            'Si el usuario ya existe, rehashea la password desde ADMIN_PASSWORD'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $email = $this->requireEnv('ADMIN_EMAIL');
            $password = $this->requireEnv('ADMIN_PASSWORD');
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $email = strtolower($email);
        $resetPassword = (bool) $input->getOption('reset-password');

        $repo = $this->em->getRepository(User::class);
        /** @var User|null $user */
        $user = $repo->findOneBy(['email' => $email]);

        if ($user === null) {
            $user = new User();
            $user->setEmail($email);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setRoles([User::ROLE_ADMIN, User::ROLE_CLIENT, User::ROLE_USER]);
            $user->setVerifiedEmail(true);

            $client = new ClientProfile();
            $client->setFullName($this->displayNameFromEmail($email));
            $client->setUser($user);
            $user->setClientProfile($client);

            $this->em->persist($user);
            $this->em->persist($client);
            $this->em->flush();

            $io->success(sprintf(
                'Admin creado: %s (roles: %s). verifiedEmail=true; sin teléfono/verifiedPhone.',
                $email,
                implode(', ', $user->getRoles())
            ));

            return Command::SUCCESS;
        }

        $roles = array_values(array_unique([
            ...$user->getRoles(),
            User::ROLE_ADMIN,
            User::ROLE_CLIENT,
            User::ROLE_USER,
        ]));
        $user->setRoles($roles);

        if ($user->getClientProfile() === null) {
            $client = new ClientProfile();
            $client->setFullName($this->displayNameFromEmail($email));
            $client->setUser($user);
            $user->setClientProfile($client);
            $this->em->persist($client);
        }

        if ($resetPassword) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $io->note('Password rehasheada desde ADMIN_PASSWORD (--reset-password).');
        }

        $this->em->flush();

        $io->success(sprintf(
            'Admin asegurado: %s (roles: %s)%s',
            $email,
            implode(', ', $user->getRoles()),
            $resetPassword ? ' [password actualizada]' : ' [password intacta]'
        ));

        return Command::SUCCESS;
    }

    private function requireEnv(string $name): string
    {
        $raw = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!\is_string($raw) || trim($raw) === '') {
            throw new \InvalidArgumentException(sprintf(
                'Falta la variable de entorno %s. Define ADMIN_EMAIL y ADMIN_PASSWORD (p. ej. railway variables set) y ejecuta: railway run php bin/console app:admin:ensure',
                $name
            ));
        }

        return trim($raw);
    }

    private function displayNameFromEmail(string $email): string
    {
        $local = explode('@', $email, 2)[0] ?: 'Admin';

        return 'Admin '.$local;
    }
}
