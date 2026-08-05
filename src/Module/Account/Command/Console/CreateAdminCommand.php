<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Console;

use App\Exception\DomainErrors;
use App\Module\Account\Command\CreateAdminUserCommand;
use App\Module\Account\Command\CreateAdminUserHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The supported way back into an instance with no reachable administrator. The
 * install wizard closes for good at the first account, so on an instance where
 * someone else registered first — or where INSTALL_TOKEN was never set — this
 * is the only path to an admin account.
 *
 * Idempotent: run it twice and the second run reports the account is already a
 * verified administrator and changes nothing.
 */
#[AsCommand(
    name: 'app:admin:create',
    description: 'Create a verified administrator, or promote and verify an existing account.',
)]
final class CreateAdminCommand extends Command
{
    /** Bytes of entropy for a password nobody typed; printed once, hex-encoded. */
    private const int GENERATED_PASSWORD_BYTES = 12;

    public function __construct(
        private readonly CreateAdminUserHandler $createAdminUser,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the administrator')
            ->addOption('full-name', null, InputOption::VALUE_REQUIRED, 'Display name; derived from the email address when omitted')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password; prompted for, or generated and printed, when omitted');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Trim before validating, not after: Assert\Email rejects surrounding
        // whitespace, and a pasted address that picked some up is the operator's
        // typo to absorb rather than report.
        $email = trim((string) $input->getArgument('email'));
        $fullName = $this->stringOption($input, 'full-name');

        $password = $this->stringOption($input, 'password');
        if (null === $password && $input->isInteractive()) {
            $answer = $io->askHidden('Password (leave empty to generate one)');
            $password = ('' === (string) $answer) ? null : (string) $answer;
        }

        // Non-interactive with no --password: generate one rather than fail, so
        // `docker exec -T` recovery works without putting a secret in shell
        // history. It is printed once below, and only when an account was made.
        $generated = null === $password;
        if (null === $password) {
            $password = bin2hex(random_bytes(self::GENERATED_PASSWORD_BYTES));
        }

        $violations = $this->violations($email, $password);
        if (0 < count($violations)) {
            foreach ($violations as $violation) {
                $io->error((string) $violation->getMessage());
            }

            return Command::FAILURE;
        }

        if ('' === $email || '' === $password) {
            throw new \LogicException('NotBlank and Length rejected these above.');
        }

        try {
            $result = ($this->createAdminUser)(new CreateAdminUserCommand(
                email: $email,
                plainPassword: $password,
                fullName: $fullName,
            ));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $io->error($this->translator->trans($translationKey));
            }

            return Command::FAILURE;
        }

        if ($result->created) {
            $io->success(sprintf('Created administrator %s.', $result->user->email));

            if ($generated) {
                $io->warning(sprintf('Generated password (shown once): %s', $password));
            }

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%s already exists — %s, %s. Its password was left untouched.',
            $result->user->email,
            $result->promoted ? 'promoted to administrator' : 'already an administrator',
            $result->verified ? 'email marked verified' : 'email already verified',
        ));

        return Command::SUCCESS;
    }

    /**
     * The handler shape-checks nothing: over HTTP an InstallAdminRequest-bound
     * form would have validated before it ever ran, and this command is that
     * entry point. Declaring the same constraints here is what keeps the two
     * paths agreeing on what a valid email and password are — a
     * filter_var() second opinion would not.
     */
    private function violations(string $email, string $password): ConstraintViolationListInterface
    {
        $violations = new ConstraintViolationList();
        $violations->addAll($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]));
        $violations->addAll($this->validator->validate($password, [new Assert\Length(min: 8)]));

        return $violations;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return (is_string($value) && '' !== $value) ? $value : null;
    }
}
