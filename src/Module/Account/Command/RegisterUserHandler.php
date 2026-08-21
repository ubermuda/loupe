<?php

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Event\UserRegistered;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Account\Service\VerificationEmailSender;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private VerificationEmailSender $verificationEmailSender,
        private RegistrationGate $registrationGate,
        private WaitlistEntryRepository $waitlistEntries,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,

        #[Autowire(param: 'app.terms.version')]
        private string $termsVersion,
    ) {
    }

    /** @throws DomainErrors */
    public function __invoke(RegisterUserCommand $command): User
    {
        // Before everything, including the invite lookup: an invite is a
        // capacity voucher, so it may reopen a full instance but must never
        // reopen one where sign-up is switched off — or, worse, mint the first
        // account on an instance whose install wizard has not run yet.
        if (!$this->registrationGate->allowsNewAccounts()) {
            throw new DomainErrors(['email' => 'account.error.registration_disabled']);
        }

        try {
            $user = $this->em->wrapInTransaction(function () use ($command): User {
                // Serialize every capacity decision (this handler and the OAuth
                // branch) behind one advisory lock, so two concurrent sign-ups
                // can never both pass a one-slot gate.
                $this->registrationGate->acquireCapacityLock($this->em->getConnection());

                // Resolved and consumed regardless of gate state: a token left
                // unconsumed while the gate happens to be open would still be a
                // live capacity-bypass credential if the gate closes again
                // before it expires.
                $invite = $this->resolveMatchingInvite($command);

                if (!$this->registrationGate->isOpen() && null === $invite) {
                    throw new DomainErrors(['email' => 'account.error.registration_closed']);
                }

                $errors = [];

                if ($this->users->findOneByEmail($command->email)) {
                    $errors['email'] = 'account.registration.error.email_duplicate';
                }

                if ([] !== $errors) {
                    throw new DomainErrors($errors);
                }

                $user = new User(
                    fullName: $command->fullName,
                    email: $command->email,
                );
                $user->password = $this->passwordHasher->hashPassword($user, $command->plainPassword);
                // The form's IsTrue-asserted agreeTerms checkbox is the consent
                // this records; it cannot be reached with the box unticked.
                $user->termsAcceptedAt = new \DateTimeImmutable();
                $user->termsVersion = $this->termsVersion;

                $this->em->persist($user);

                // A matching invite bypassed the gate above (if closed) and is
                // always converted. Additionally house-keep: a person who
                // joined the waitlist earlier but registers normally once the
                // cap reopens (no token involved) still has a waitlist row —
                // it must not linger as "waiting" once their account exists.
                ($invite ?? $this->waitlistEntries->findOneByEmail($command->email))?->markConverted();

                // One flush: user creation and invite conversion commit together or not at all.
                $this->em->flush();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration won the race between the pre-checks
            // above and this flush; surface the same field error instead of
            // letting the request 500. The EM is closed at this point, so the
            // colliding field cannot be re-queried — email is the likely one.
            throw new DomainErrors(['email' => 'account.registration.error.email_duplicate']);
        }

        try {
            $this->verificationEmailSender->send($user);
        } catch (\Throwable) {
            // Email failed to enqueue; account is created — user can resend from check-email page.
        }

        // Listeners run outside the committed registration transaction, so their
        // failures must not surface: a 500 would tell the user their created
        // account failed, and the retry dead-ends on "email already taken". Trial
        // provisioning self-heals via PaywallGate, so log and move on.
        try {
            $this->eventDispatcher->dispatch(new UserRegistered($user));
        } catch (\Throwable $e) {
            $this->logger->warning('account.registration.listener_failed', [
                'userId' => (string) $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }

    /**
     * Resolves the invite the command's token points to, revalidating it under
     * a row lock (a concurrent redemption may have converted it since the
     * caller's first lookup). Returns null for a missing, expired, converted,
     * or otherwise-invalid token, and also for a token whose invited address
     * does not match the address being registered — the token is a capacity
     * voucher issued to one address, and possession alone (a forwarded or
     * leaked link) must not let a different address claim it.
     */
    private function resolveMatchingInvite(RegisterUserCommand $command): ?WaitlistEntry
    {
        if (null === $command->inviteToken) {
            return null;
        }

        $invite = $this->waitlistEntries->findOneByValidInviteToken($command->inviteToken);
        if (null === $invite) {
            return null;
        }

        $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($invite);

        if (!$invite->isInviteTokenValid($command->inviteToken)) {
            return null;
        }

        if (!$invite->isInviteFor($command->email)) {
            return null;
        }

        return $invite;
    }
}
