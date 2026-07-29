<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Console;

use App\Exception\DomainErrors;
use App\Module\Account\Command\MarkEmailVerifiedCommand;
use App\Module\Account\Command\MarkEmailVerifiedHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Marks an account's email verified without the emailed link — the recovery
 * path for an instance whose outbound mail never arrives, which otherwise
 * parks every account on the check-email page forever. Idempotent: verifying a
 * verified account succeeds and changes nothing.
 */
#[AsCommand(
    name: 'app:user:verify',
    description: "Mark an account's email address as verified.",
)]
final class VerifyUserCommand extends Command
{
    public function __construct(
        private readonly MarkEmailVerifiedHandler $markEmailVerified,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address of the account to verify');
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
            $result = ($this->markEmailVerified)(new MarkEmailVerifiedCommand($email));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $io->error($this->translator->trans($translationKey));
            }

            return Command::FAILURE;
        }

        if ($result->verified) {
            $io->success(sprintf("Marked %s's email as verified.", $email));

            return Command::SUCCESS;
        }

        // Worth saying out loud: an operator running this on an account they are
        // worried about has just invalidated a working login link, and nothing
        // else in the output would tell them.
        $io->success($result->tokenRevoked
            ? sprintf("%s's email is already verified — revoked an outstanding verification link.", $email)
            : sprintf("%s's email is already verified.", $email));

        return Command::SUCCESS;
    }
}
