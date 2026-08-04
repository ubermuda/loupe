<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Console;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Full-stack runs of the three recovery commands. They are the only way back
 * into an instance whose install wizard has closed with no reachable
 * administrator, so each one is exercised against the real container and the
 * real database, including the second run that must stay a no-op.
 */
final class AdminRecoveryCommandsTest extends KernelTestCase
{
    private KernelInterface $bootedKernel;
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        $this->bootedKernel = self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_admin_create_makes_a_usable_administrator_and_prints_the_generated_password(): void
    {
        $tester = $this->tester('app:admin:create');

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'rescue@example.com'], ['interactive' => false]));
        self::assertStringContainsString('Created administrator rescue@example.com', $tester->getDisplay());
        self::assertStringContainsString('Generated password', $tester->getDisplay());

        $user = $this->find('rescue@example.com');
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertTrue($user->isVerified());
        self::assertTrue($user->hasUsablePassword());
    }

    public function test_admin_create_is_idempotent(): void
    {
        $tester = $this->tester('app:admin:create');
        $tester->execute(['email' => 'rerun@example.com'], ['interactive' => false]);
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'rerun@example.com'], ['interactive' => false]));
        // Substrings only: SymfonyStyle hard-wraps the block, so a longer
        // phrase would straddle a line break.
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertStringContainsString('already an administrator', $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function malformedInput(): iterable
    {
        yield 'email' => [['email' => 'not-an-email'], 'not a valid email address'];
        yield 'password' => [['email' => 'ok@example.com', '--password' => 'short'], 'too short'];
    }

    /**
     * The handler validates none of this — the console command is the entry
     * point, and it applies the same constraints the install form's DTO does.
     *
     * @param array<string, string> $input
     */
    #[DataProvider('malformedInput')]
    public function test_admin_create_rejects_malformed_input(array $input, string $expected): void
    {
        $tester = $this->tester('app:admin:create');

        self::assertSame(Command::FAILURE, $tester->execute($input, ['interactive' => false]));
        // Collapse whitespace first: SymfonyStyle hard-wraps its error block, so
        // any phrase long enough to be distinctive straddles a line break.
        self::assertStringContainsString($expected, (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertNull($users->findOneByEmail($input['email']));
    }

    public function test_user_promote_grants_the_role_then_reports_a_no_op(): void
    {
        $this->persistUser('promote@example.com', 'promote');

        $tester = $this->tester('app:user:promote');

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'promote@example.com']));
        self::assertStringContainsString('Promoted promote@example.com', $tester->getDisplay());

        $this->em->clear();
        self::assertContains('ROLE_ADMIN', $this->find('promote@example.com')->getRoles());

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'promote@example.com']));
        self::assertStringContainsString('already an administrator', $tester->getDisplay());
    }

    public function test_user_verify_marks_the_email_then_reports_a_no_op(): void
    {
        $this->persistUser('verify@example.com', 'verify');

        $tester = $this->tester('app:user:verify');

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'verify@example.com']));
        self::assertStringContainsString('as verified', $tester->getDisplay());

        $this->em->clear();
        self::assertTrue($this->find('verify@example.com')->isVerified());

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'verify@example.com']));
        self::assertStringContainsString('already verified', $tester->getDisplay());
    }

    public function test_user_verify_reports_revoking_a_stale_link_on_a_verified_account(): void
    {
        $user = $this->persistUser('stale-link@example.com', 'stalelink');
        $user->generateEmailVerificationToken();
        $user->emailVerifiedAt = new \DateTimeImmutable('-1 day');
        $this->em->flush();

        $tester = $this->tester('app:user:verify');

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'stale-link@example.com']));
        // Collapse whitespace: SymfonyStyle hard-wraps its success block.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('already verified', $display);
        self::assertStringContainsString('revoked an outstanding verification link', $display);

        $this->em->clear();
        self::assertFalse($this->find('stale-link@example.com')->hasEmailVerificationToken());
    }

    /** @return iterable<string, array{string}> */
    public static function accountScopedCommands(): iterable
    {
        yield 'promote' => ['app:user:promote'];
        yield 'verify' => ['app:user:verify'];
    }

    #[DataProvider('accountScopedCommands')]
    public function test_an_unknown_email_fails_with_a_translated_message(string $commandName): void
    {
        $tester = $this->tester($commandName);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'ghost@example.com']));
        self::assertStringContainsString('No account exists', $tester->getDisplay());
    }

    private function tester(string $name): CommandTester
    {
        return new CommandTester(new Application($this->bootedKernel)->find($name));
    }

    /** @param non-empty-string $email */
    private function persistUser(string $email, string $username): User
    {
        $user = new User(fullName: 'Test User', email: $email);
        $user->password = 'not-a-real-hash';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function find(string $email): User
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
