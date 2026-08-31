<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Admin\AdminUserGuard;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\VerificationEmailSender;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UpdateUserHandler
{
    public function __construct(
        private AdminUserGuard $guard,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private VerificationEmailSender $verificationEmails,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when the guard refuses the role change, or the email is already taken */
    public function __invoke(UpdateUserCommand $command): User
    {
        $target = $command->target;
        $roles = $this->rolesWithAdmin($target->roles, $command->isAdmin);

        // Before any mutation: a refused change must leave the account untouched.
        $this->guard->assertRolesAssignable($target, $command->actor, $roles);

        // User::$email lowercases on write, so comparing the raw input would
        // read a pure case edit as a change and revoke a verified address.
        $email = strtolower(trim($command->email));
        if ('' === $email) {
            throw new \LogicException('Email is required; the form must reject a blank one before reaching here.');
        }
        $fullName = trim($command->fullName);
        $emailChanged = $email !== $target->email;

        // Compared before anything is assigned: a resubmitted form that changes
        // no field is accepted, not refused, and must not leave a record
        // claiming a transition the database never made.
        if (!$emailChanged
            && $fullName === $target->fullName
            && $roles === $target->roles
            && $command->isVerified === $target->isVerified()) {
            return $target;
        }

        if ($emailChanged && null !== $this->users->findOneByEmail($email)) {
            throw new DomainErrors(['email' => 'account.admin.users.error.email_taken']);
        }

        $target->fullName = $fullName;
        $target->roles = $roles;

        if ($emailChanged) {
            // An admin must not be able to hand someone a verified address they
            // do not control, so the new one starts unverified whatever the box said.
            $target->email = $email;
            $target->emailVerifiedAt = null;
        } elseif ($command->isVerified !== $target->isVerified()) {
            $target->emailVerifiedAt = $command->isVerified ? new \DateTimeImmutable() : null;
        }

        $this->em->flush();

        if ($emailChanged) {
            $this->verificationEmails->send($target);
        }

        // The admin is the actor the Auditor resolves from the security token,
        // so naming them again in the context would only let the two drift.
        $this->auditor->record(
            'account.admin.user_updated',
            AuditOutcome::Success,
            [
                'userId' => (string) $target->id,
                'emailChanged' => $emailChanged,
            ],
            new AuditSubject('user', (string) $target->id),
        );

        return $target;
    }

    /**
     * @param list<string> $current
     *
     * @return list<string>
     */
    private function rolesWithAdmin(array $current, bool $isAdmin): array
    {
        $roles = array_values(array_filter($current, static fn (string $role): bool => 'ROLE_ADMIN' !== $role));

        if ($isAdmin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }
}
