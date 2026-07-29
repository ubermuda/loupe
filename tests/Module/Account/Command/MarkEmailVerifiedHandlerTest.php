<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\MarkEmailVerifiedCommand;
use App\Module\Account\Command\MarkEmailVerifiedHandler;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarkEmailVerifiedHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MarkEmailVerifiedHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(MarkEmailVerifiedHandler::class);
        self::assertInstanceOf(MarkEmailVerifiedHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_verifies_and_burns_the_outstanding_token(): void
    {
        $user = $this->persistUser('verify-me@example.com');
        $token = $user->generateEmailVerificationToken();
        $this->em->flush();

        $result = ($this->handler)(new MarkEmailVerifiedCommand('verify-me@example.com'));
        self::assertTrue($result->verified);
        self::assertTrue($result->tokenRevoked);

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isVerified());
        // The emailed link must stop working: a token that still resolves after
        // the account is verified is a live credential nobody needs.
        self::assertFalse($reloaded->isEmailVerificationTokenValid($token));
    }

    public function test_verifying_a_verified_account_changes_nothing(): void
    {
        $user = $this->persistUser('already-verified@example.com');
        $verifiedAt = new \DateTimeImmutable('-1 day');
        $user->emailVerifiedAt = $verifiedAt;
        $this->em->flush();

        $result = ($this->handler)(new MarkEmailVerifiedCommand('already-verified@example.com'));
        self::assertFalse($result->verified);
        self::assertFalse($result->tokenRevoked);

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->id);
        self::assertNotNull($reloaded);
        // Second-precision: Postgres does not round-trip the microseconds.
        self::assertNotNull($reloaded->emailVerifiedAt);
        self::assertSame($verifiedAt->format('Y-m-d H:i:s'), $reloaded->emailVerifiedAt->format('Y-m-d H:i:s'));
    }

    public function test_it_revokes_a_stale_token_left_on_an_already_verified_account(): void
    {
        // The state social login leaves behind: ResolveSocialLoginHandler sets
        // emailVerifiedAt on a match-by-email without touching the token the
        // form registration already emailed.
        $user = $this->persistUser('social-linked@example.com');
        $token = $user->generateEmailVerificationToken();
        $user->emailVerifiedAt = new \DateTimeImmutable('-1 day');
        $this->em->flush();

        // Guard: the link really does work before the command runs, so the
        // assertion below cannot pass against a token that was never live.
        self::assertTrue($user->isEmailVerificationTokenValid($token));

        $result = ($this->handler)(new MarkEmailVerifiedCommand('social-linked@example.com'));
        self::assertFalse($result->verified);
        self::assertTrue($result->tokenRevoked);

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->id);
        self::assertNotNull($reloaded);
        // /register/verify logs in whoever presents a valid token, so leaving
        // this one alive is leaving a working login link outstanding.
        self::assertFalse($reloaded->hasEmailVerificationToken());
        self::assertFalse($reloaded->isEmailVerificationTokenValid($token));
    }

    public function test_an_unknown_email_is_a_domain_error(): void
    {
        $this->expectException(DomainErrors::class);

        ($this->handler)(new MarkEmailVerifiedCommand('nobody@example.com'));
    }

    /** @param non-empty-string $email */
    private function persistUser(string $email): User
    {
        $user = new User(username: explode('@', $email)[0], fullName: 'Test User', email: $email);
        $user->password = 'not-a-real-hash';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
