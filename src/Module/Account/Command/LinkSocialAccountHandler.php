<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\SocialLoginRace;
use App\Module\Account\Service\StaleSocialLink;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final readonly class LinkSocialAccountHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(LinkSocialAccountCommand $command): ConnectedAccount
    {
        $user = $this->resolveUser($command);

        if (!$this->passwordHasher->isPasswordValid($user, $command->plainPassword)) {
            throw new DomainErrors(['password' => 'account.social.link.error.invalid_password']);
        }

        // Password confirmed and the provider asserted this email is verified, so
        // any pending click-through verification is superseded. Revoking the
        // token is what makes that true: VerifyEmailHandler never checks
        // isVerified() and VerifyEmailController logs in whoever presents a valid
        // token, so a link left outstanding here keeps working after the account
        // is already verified by another route.
        if ($command->profile->emailVerified) {
            $user->emailVerifiedAt ??= new \DateTimeImmutable();
            $user->clearEmailVerificationToken();
        }

        $account = new ConnectedAccount(
            user: $user,
            provider: $command->profile->provider,
            providerUserId: $command->profile->providerUserId,
            email: $command->profile->email,
        );
        $this->em->persist($account);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request already claimed this identity. The
            // EntityManager is closed, so the caller sends the user back to the
            // login page; a fresh OAuth round-trip resolves through the winning
            // link.
            throw new SocialLoginRace('A concurrent request already linked this social identity.', previous: $e);
        }

        return $account;
    }

    /**
     * Re-establishes, at confirmation time, every condition that made the
     * pending link legitimate: the account still exists, is still password
     * protected, and still owns the verified provider email. Anything else and
     * the provider no longer proves ownership of this account.
     */
    private function resolveUser(LinkSocialAccountCommand $command): User
    {
        if (!Uuid::isValid($command->userId)) {
            throw new StaleSocialLink('The pending social link carries an unusable account id.');
        }

        $user = $this->users->find(Uuid::fromString($command->userId));
        $email = $command->profile->email;

        if (null === $user || !$user->hasUsablePassword() || null === $email || $user->email !== strtolower($email)) {
            throw new StaleSocialLink('The account behind the pending social link changed since the callback.');
        }

        return $user;
    }
}
