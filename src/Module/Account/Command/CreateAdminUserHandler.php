<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\InstallFlagsRequest;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\UsernameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Ensures the given email is a verified administrator, creating the account
 * when it does not exist yet. Deliberately does NOT compose
 * CreateInstallAdminHandler: that one refuses outright once any account exists,
 * which is precisely the situation this handler has to recover from. It also
 * skips the verification email — an operator running this from a shell is
 * recovering an instance whose mail may well be the thing that is broken.
 *
 * An existing account keeps its password: an operator asking for an
 * administrator has not asked to reset anyone's credentials.
 */
final readonly class CreateAdminUserHandler
{
    /** Mirrors the minimum the install wizard and the registration form enforce. */
    private const int MIN_PASSWORD_LENGTH = 8;

    private const int MAX_FULL_NAME_LENGTH = 150;

    /** The shape InstallAdminRequest accepts — reserved names stay allowed, as they do in the wizard. */
    private const string USERNAME_PATTERN = '/^[a-z][a-z0-9_-]{2,29}$/';

    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private UsernameGenerator $usernameGenerator,
        private PromoteUserToAdminHandler $promoteUserToAdmin,
        private MarkEmailVerifiedHandler $markEmailVerified,
        private SeedInstallFlagsHandler $seedInstallFlags,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws DomainErrors */
    public function __invoke(CreateAdminUserCommand $command): CreateAdminUserResult
    {
        $email = strtolower(trim($command->email));
        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainErrors(['email' => 'account.console.error.email_invalid']);
        }

        $existing = $this->users->findOneByEmail($email);
        if (null !== $existing) {
            $result = new CreateAdminUserResult(
                user: $existing,
                created: false,
                promoted: ($this->promoteUserToAdmin)(new PromoteUserToAdminCommand($email)),
                verified: ($this->markEmailVerified)(new MarkEmailVerifiedCommand($email)),
            );
            $this->seedMissingInstallFlags();

            return $result;
        }

        if (strlen($command->plainPassword) < self::MIN_PASSWORD_LENGTH) {
            throw new DomainErrors(['plainPassword' => 'account.console.error.password_too_short']);
        }

        $localPart = explode('@', $email)[0];
        $username = $this->resolveUsername($command->username, $localPart);

        $user = new User(
            username: $username,
            fullName: substr(trim($command->fullName ?? '') ?: $localPart, 0, self::MAX_FULL_NAME_LENGTH),
            email: $email,
        );
        $user->password = $this->passwordHasher->hashPassword($user, $command->plainPassword);
        $user->roles = ['ROLE_ADMIN'];
        $user->emailVerifiedAt = new \DateTimeImmutable();

        $this->em->persist($user);
        $this->em->flush();

        $this->logger->info('account.admin.created_from_console', ['userId' => (string) $user->id]);

        $this->seedMissingInstallFlags();

        return new CreateAdminUserResult(user: $user, created: true, promoted: false, verified: false);
    }

    /**
     * An instance recovered from the shell never ran the wizard's first step, so
     * it would otherwise have an administrator but none of the feature-flag rows
     * the app expects. Seeding is idempotent — existing rows are left alone — so
     * this converges CLI recovery on the same end state as the wizard.
     */
    private function seedMissingInstallFlags(): void
    {
        // The form DTO is the single source of truth for the install defaults.
        $defaults = new InstallFlagsRequest();

        ($this->seedInstallFlags)(new SeedInstallFlagsCommand(
            registrationCap: $defaults->registrationCap ?? 0,
            registrationEnabled: $defaults->registrationEnabled,
            billingEnabled: $defaults->billingEnabled,
            billingTrialDays: $defaults->billingTrialDays ?? 0,
            billingStripePriceId: $defaults->billingStripePriceId,
            authGithubEnabled: $defaults->authGithubEnabled,
            authGoogleEnabled: $defaults->authGoogleEnabled,
        ));
    }

    /**
     * An explicit username is taken at face value or rejected — silently
     * suffixing it would hand the operator a login they did not ask for. Only
     * the derived fallback is allowed to pick its own free variant.
     *
     * @return non-empty-string
     *
     * @throws DomainErrors
     */
    private function resolveUsername(?string $username, string $localPart): string
    {
        $username = trim($username ?? '');

        if ('' === $username) {
            return $this->usernameGenerator->fromPreferred($localPart);
        }

        if (1 !== preg_match(self::USERNAME_PATTERN, $username)) {
            throw new DomainErrors(['username' => 'account.console.error.username_invalid']);
        }

        if (null !== $this->users->findOneByUsername($username)) {
            throw new DomainErrors(['username' => 'account.console.error.username_taken']);
        }

        return $username;
    }
}
