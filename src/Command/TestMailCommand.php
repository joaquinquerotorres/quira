<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(name: 'app:test-mail', description: 'Envía un correo de prueba a MailHog')]
final class TestMailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $email = (new Email())
                ->from('no-reply@quira.app')
                ->to('test@example.com')
                ->subject('Test MailHog - Quira')
                ->html('<p>Si ves esto en http://localhost:8025, el correo funciona.</p>');

            $this->mailer->send($email);
            $io->success('Correo enviado. Revisa http://localhost:8025');
        } catch (\Throwable $e) {
            $io->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
