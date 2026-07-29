<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Console;

use App\Exception\DomainErrors;
use App\Module\Account\Command\PromoteUserToAdminCommand;
use App\Module\Account\Command\PromoteUserToAdminHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Grants ROLE_ADMIN to an account that already exists — the recovery path when
 * the operator signed up through /register and now needs the admin area.
 * Idempotent: promoting an administrator again succeeds and changes nothing.
 */
#[AsCommand(
    name: 'app:user:promote',
    description: 'Grant ROLE_ADMIN to an existing account.',
)]
final class PromoteUserCommand extends Command
{
    public function __construct(
        private readonly PromoteUserToAdminHandler $promoteUserToAdmin,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address of the account to promote');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        if ('' === $email) {
            $io->error('The email argument must not be empty.');

            return Command::FAILURE;
        }

        try {
            $promoted = ($this->promoteUserToAdmin)(new PromoteUserToAdminCommand($email));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $io->error($this->translator->trans($translationKey));
            }

            return Command::FAILURE;
        }

        $io->success($promoted
            ? sprintf('Promoted %s to administrator.', $email)
            : sprintf('%s is already an administrator.', $email));

        return Command::SUCCESS;
    }
}
