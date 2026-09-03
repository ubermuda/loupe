<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\User;
use App\Module\Account\Event\UserRegistered;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\DisplayNameDeriver;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Account\Service\SocialLoginOutcome;
use App\Module\Account\Service\SocialLoginRace;
use App\Module\Account\Service\SocialProfile;
use App\Module\Account\Service\UnverifiedProviderEmail;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ResolveSocialLoginHandler
{
    public function __construct(
        private ConnectedAccountRepository $connectedAccounts,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private RegistrationGate $registrationGate,
        private JoinWaitlistHandler $joinWaitlist,
        private WaitlistEntryRepository $waitlistEntries,
        private EventDispatcherInterface $eventDispatcher,
        private DisplayNameDeriver $displayNameDeriver,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ResolveSocialLoginCommand $command): SocialLoginOutcome
    {
        $profile = $command->profile;

        $existing = $this->connectedAccounts->findOneByProviderAndProviderUserId($profile->provider, $profile->providerUserId);
        if (null !== $existing) {
            return SocialLoginOutcome::logIn($existing->user);
        }

        // A provider email is trustworthy for matching only when the provider
        // asserts it is verified — trusting an unverified one is the nOAuth
        // account-takeover path. With none, the login is rejected outright,
        // since User.email is the non-nullable login identity.
        if (!$profile->emailVerified || null === $profile->email || '' === $profile->email) {
            throw new UnverifiedProviderEmail();
        }
        $matchEmail = $profile->email;

        $byEmail = $this->users->findOneByEmail($matchEmail);
        if (null !== $byEmail) {
            // The account is password-protected, so proving control of the same
            // address is not enough to take it over: the user must confirm the
            // password before the identity is linked.
            if ($byEmail->hasUsablePassword()) {
                return SocialLoginOutcome::requiresPasswordLink($byEmail);
            }

            // The provider proved ownership of this verified email — a pending
            // click-through verification is superseded. Revoking the token is
            // what makes that true: VerifyEmailHandler never checks isVerified()
            // and VerifyEmailController logs in whoever presents a valid token,
            // so a link left outstanding here keeps working afterwards.
            $byEmail->emailVerifiedAt ??= new \DateTimeImmutable();
            $byEmail->clearEmailVerificationToken();
            $this->em->persist($this->link($byEmail, $profile));
            $this->flushOrRace();

            return SocialLoginOutcome::logIn($byEmail);
        }

        // Gated here and not earlier: only this branch creates an account, and
        // an existing user must still be able to log in on a closed instance.
        // No waitlist diversion either — a switched-off instance is not merely
        // full, so there is nothing an entry could be waiting for.
        if (!$this->registrationGate->allowsNewAccounts()) {
            $this->auditor->record(
                'account.social_registration_closed',
                AuditOutcome::Refused,
                ['provider' => $profile->provider->value],
            );

            return SocialLoginOutcome::registrationClosed();
        }

        // The capacity decision (lock + isOpen + creation) is one transaction;
        // the waitlist join runs after it rather than inside, because a caught
        // DBAL uniqueness exception still aborts the surrounding Postgres
        // transaction.
        $user = $this->em->wrapInTransaction(function () use ($profile, $matchEmail): ?User {
            // Serialize this capacity decision against the form registration
            // handler's — the same advisory lock, so the two paths can never
            // both take the last slot.
            $this->registrationGate->acquireCapacityLock($this->em->getConnection());

            if (!$this->registrationGate->isOpen()) {
                return null;
            }

            // The provider's name is real data and worth keeping; there is no
            // form to ask on when it sends none, so the address is the only
            // material left to build one from.
            $providerName = trim($profile->fullName ?? '');
            $user = new User(
                fullName: '' !== $providerName
                    ? mb_substr($providerName, 0, User::MAX_FULL_NAME_LENGTH)
                    : $this->displayNameDeriver->derive($matchEmail),
                email: $matchEmail,
            );
            $user->emailVerifiedAt = new \DateTimeImmutable();
            $this->em->persist($user);
            $this->em->persist($this->link($user, $profile));

            // House-keep: this address may have joined the waitlist earlier
            // (directly, or via a previous at-cap OAuth attempt) and is only
            // now creating an account because the cap reopened. That row must
            // not linger as "waiting" once the account exists.
            $this->waitlistEntries->findOneByEmail($matchEmail)?->markConverted();

            $this->flushOrRace();

            return $user;
        });

        if (null === $user) {
            ($this->joinWaitlist)(new JoinWaitlistCommand($matchEmail));
            $this->auditor->record(
                'account.waitlist_oauth_diverted',
                AuditOutcome::Refused,
                ['provider' => $profile->provider->value],
            );

            return SocialLoginOutcome::waitlisted();
        }

        // Recorded after the transaction commits, so a rollback cannot leave a
        // record claiming an account that does not exist.
        $this->auditor->record(
            'account.registered',
            AuditOutcome::Success,
            [
                'userId' => (string) $user->id,
                'provider' => $profile->provider->value,
            ],
            new AuditSubject('user', (string) $user->id),
        );

        // Listeners run outside the committed creation transaction, so their
        // failures cannot roll the account back — and must not surface: an error
        // here fails an OAuth login whose account exists, and the retry takes the
        // existing-identity branch. Trial provisioning self-heals via
        // PaywallGate, so record it and complete the login.
        try {
            $this->eventDispatcher->dispatch(new UserRegistered($user));
        } catch (\Throwable) {
            // The listener's own exception stays with whatever reports it: the
            // record says the registration's follow-up work broke, and never why.
            $this->auditor->record(
                'account.registration_listener_failed',
                AuditOutcome::Failed,
                ['userId' => (string) $user->id],
                new AuditSubject('user', (string) $user->id),
            );
        }

        return SocialLoginOutcome::logIn($user);
    }

    private function flushOrRace(): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent callback won the race on users.email or on the
            // identity's unique (provider, provider_user_id). The EntityManager
            // is closed, so nothing can be recovered in this request.
            throw new SocialLoginRace('A concurrent social login won a uniqueness race.', previous: $e);
        }
    }

    private function link(User $user, SocialProfile $profile): ConnectedAccount
    {
        return new ConnectedAccount(
            user: $user,
            provider: $profile->provider,
            providerUserId: $profile->providerUserId,
            email: $profile->email,
        );
    }
}
