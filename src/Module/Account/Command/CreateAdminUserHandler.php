<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\AgentAccountInstaller;
use App\Module\Account\Service\DisplayNameDeriver;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Ensures the given email is a verified administrator, creating the account when
 * it does not exist and leaving an existing one's password alone. Cannot compose
 * CreateInstallAdminHandler — that refuses once any account exists, which is the
 * state this recovers from — and sends no verification email, because on an
 * instance being recovered from a shell mail may be the thing that is broken.
 */
final readonly class CreateAdminUserHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private PromoteUserToAdminHandler $promoteUserToAdmin,
        private MarkEmailVerifiedHandler $markEmailVerified,
        private Auditor $auditor,
        private DisplayNameDeriver $displayNameDeriver,

        #[Autowire(param: 'app.terms.version')]
        private string $termsVersion,
    ) {
    }

    /** @throws DomainErrors */
    public function __invoke(CreateAdminUserCommand $command): CreateAdminUserResult
    {
        $email = strtolower($command->email);

        // Before the existing-user branch, not after the create: this command is
        // the documented repair for an unreachable /install, and an operator
        // running it usually supplies an email that already exists, which
        // returns early. Idempotent, so running it every time costs nothing.
        AgentAccountInstaller::install($this->em->getConnection());

        $existing = $this->users->findOneByEmail($email);
        if (null !== $existing) {
            return new CreateAdminUserResult(
                user: $existing,
                created: false,
                promoted: ($this->promoteUserToAdmin)(new PromoteUserToAdminCommand($email)),
                verified: ($this->markEmailVerified)(new MarkEmailVerifiedCommand($email))->verified,
            );
        }

        // Every account has a display name and this entry point has no form to
        // ask on, so an omitted --full-name is derived from the address.
        $fullName = trim($command->fullName ?? '');

        $user = new User(
            fullName: '' !== $fullName
                ? mb_substr($fullName, 0, User::MAX_FULL_NAME_LENGTH)
                : $this->displayNameDeriver->derive($email),
            email: $email,
        );
        $user->password = $this->passwordHasher->hashPassword($user, $command->plainPassword);
        $user->roles = ['ROLE_ADMIN'];
        $user->emailVerifiedAt = new \DateTimeImmutable();
        // A console entry point has no form to present the terms on, and this
        // is the documented repair for an instance whose admin is locked out —
        // gating the account it mints would lock them out again.
        $user->termsAcceptedAt = new \DateTimeImmutable();
        $user->termsVersion = $this->termsVersion;

        $this->em->persist($user);
        $this->em->flush();

        $this->auditor->record(
            'account.admin_created_from_console',
            AuditOutcome::Success,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
        );

        return new CreateAdminUserResult(user: $user, created: true, promoted: false, verified: false);
    }
}
