<?php

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Event\UserRegistered;
use App\Module\Account\Registration\RegistrationPasses;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Account\Service\VerificationEmailSender;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private VerificationEmailSender $verificationEmailSender,
        private RegistrationGate $registrationGate,
        private WaitlistEntryRepository $waitlistEntries,
        private RegistrationPasses $registrationPasses,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private Auditor $auditor,

        #[Autowire(param: 'app.terms.version')]
        private string $termsVersion,
    ) {
    }

    /** @throws DomainErrors */
    public function __invoke(RegisterUserCommand $command): User
    {
        // Before everything, including the pass lookup: a pass is a capacity
        // voucher, so it may reopen a full instance but must never
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

                $user = new User(
                    fullName: $command->fullName,
                    email: $command->email,
                );

                // Redeemed regardless of gate state: a token left unconsumed
                // while the gate happens to be open would still be a live
                // capacity-bypass credential if the gate closes again before
                // it expires.
                $redeemed = null !== $command->inviteToken
                    && $this->registrationPasses->redeem($command->inviteToken, $user);

                if (!$this->registrationGate->isOpen() && !$redeemed) {
                    throw new DomainErrors(['email' => 'account.error.registration_closed']);
                }

                if ($this->users->findOneByEmail($command->email)) {
                    throw new DomainErrors(['email' => 'account.registration.error.email_duplicate']);
                }

                $user->password = $this->passwordHasher->hashPassword($user, $command->plainPassword);
                // The form's IsTrue-asserted agreeTerms checkbox is the consent
                // this records; it cannot be reached with the box unticked.
                $user->termsAcceptedAt = new \DateTimeImmutable();
                $user->termsVersion = $this->termsVersion;

                $this->em->persist($user);

                // House-keep: a person who joined the waitlist earlier may
                // register once the cap reopens, with no token involved. Their
                // row must not linger as "waiting" once the account exists.
                $this->waitlistEntries->findOneByEmail($command->email)?->markConverted();

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

        // Recorded after the transaction commits, so a rollback cannot leave a
        // record claiming an account that does not exist. `provider` is null on
        // this path and names the provider on the social one, so both branches
        // share the operation.
        $this->auditor->record(
            'account.registered',
            AuditOutcome::Success,
            [
                'userId' => (string) $user->id,
                'provider' => null,
            ],
            new AuditSubject('user', (string) $user->id),
        );

        try {
            $this->verificationEmailSender->send($user);
        } catch (\Throwable) {
            // Email failed to enqueue; account is created — user can resend from check-email page.
        }

        // Listeners run outside the committed registration transaction, so their
        // failures must not surface: a 500 would tell the user their created
        // account failed, and the retry dead-ends on "email already taken". Trial
        // provisioning self-heals via PaywallGate, so record it and move on.
        try {
            $this->eventDispatcher->dispatch(new UserRegistered($user));
        } catch (\Throwable $e) {
            $this->auditor->record(
                'account.registration_listener_failed',
                AuditOutcome::Failed,
                ['userId' => (string) $user->id],
                new AuditSubject('user', (string) $user->id),
            );

            // The record says the follow-up work broke. Which listener and why
            // is the exception message, which never reaches the trail.
            $this->logger->warning('account.registration_listener_failed', [
                'userId' => (string) $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }
}
