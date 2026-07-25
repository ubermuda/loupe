<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Account\Service\SocialLoginOutcome;
use App\Module\Account\Service\SocialLoginRace;
use App\Module\Account\Service\SocialProfile;
use App\Module\Account\Service\UnverifiedProviderEmail;
use App\Module\Account\Service\UsernameGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class ResolveSocialLoginHandler
{
    public function __construct(
        private ConnectedAccountRepository $connectedAccounts,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UsernameGenerator $usernameGenerator,
        private RegistrationGate $registrationGate,
        private JoinWaitlistHandler $joinWaitlist,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResolveSocialLoginCommand $command): SocialLoginOutcome
    {
        $profile = $command->profile;

        $existing = $this->connectedAccounts->findOneByProviderAndProviderUserId($profile->provider, $profile->providerUserId);
        if (null !== $existing) {
            return SocialLoginOutcome::logIn($existing->user);
        }

        // A provider email may be trusted for matching/linking ONLY when the
        // provider asserted it is verified — trusting an unverified one lets an
        // attacker claim someone else's address (nOAuth account takeover). Loupe
        // additionally requires every account to have a verified email
        // (User.email is non-nullable and is the login identity), so with no
        // verified email the login is rejected outright rather than creating an
        // email-less account.
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
            // click-through verification is superseded.
            $byEmail->emailVerifiedAt ??= new \DateTimeImmutable();
            $this->em->persist($this->link($byEmail, $profile));
            $this->flushOrRace();

            return SocialLoginOutcome::logIn($byEmail);
        }

        // Branch D: no existing identity or account matches — either create a
        // new verified account or, if the registration cap is closed, divert
        // the verified provider email to the waitlist instead.
        return $this->em->wrapInTransaction(function () use ($profile, $matchEmail): SocialLoginOutcome {
            // Serialize this capacity decision against the form registration
            // handler's — the same advisory lock, so the two paths can never
            // both take the last slot.
            $this->registrationGate->acquireCapacityLock($this->em->getConnection());

            if (!$this->registrationGate->isOpen()) {
                ($this->joinWaitlist)(new JoinWaitlistCommand($matchEmail));
                $this->logger->info('account.waitlist.oauth_diverted', ['provider' => $profile->provider->value]);

                return SocialLoginOutcome::waitlisted();
            }

            $user = new User(
                username: $this->usernameGenerator->fromPreferred($profile->fullName ?? explode('@', $matchEmail)[0]),
                fullName: substr(trim($profile->fullName ?? '') ?: explode('@', $matchEmail)[0], 0, 150),
                email: $matchEmail,
            );
            $user->emailVerifiedAt = new \DateTimeImmutable();
            $this->em->persist($user);
            $this->em->persist($this->link($user, $profile));
            $this->flushOrRace();

            return SocialLoginOutcome::logIn($user);
        });
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
