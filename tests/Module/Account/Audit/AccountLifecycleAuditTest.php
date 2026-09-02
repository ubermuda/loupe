<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Audit;

use App\Module\Account\Command\AcceptTermsCommand;
use App\Module\Account\Command\AcceptTermsHandler;
use App\Module\Account\Command\CreateAdminUserCommand;
use App\Module\Account\Command\CreateAdminUserHandler;
use App\Module\Account\Command\MarkEmailVerifiedCommand;
use App\Module\Account\Command\MarkEmailVerifiedHandler;
use App\Module\Account\Command\PromoteUserToAdminCommand;
use App\Module\Account\Command\PromoteUserToAdminHandler;
use App\Module\Account\Command\RequestPasswordResetCommand;
use App\Module\Account\Command\RequestPasswordResetHandler;
use App\Module\Account\Command\ResendVerificationEmailCommand;
use App\Module\Account\Command\ResendVerificationEmailHandler;
use App\Module\Account\Command\ResetPasswordCommand;
use App\Module\Account\Command\ResetPasswordHandler;
use App\Module\Account\Command\UpdateProfileCommand;
use App\Module\Account\Command\UpdateProfileHandler;
use App\Module\Account\Command\VerifyEmailCommand;
use App\Module\Account\Command\VerifyEmailHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\PasswordResetEmailSender;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The account's own lifecycle: terms, profile, credentials and the operator
 * escape hatches. The credential handlers recorded nothing at all before this,
 * so a password change and an email verification left no trail.
 */
final class AccountLifecycleAuditTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordingAuditor $audit;

    public function test_accepting_the_terms_records_the_version(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-terms@example.com');

        $this->handler(AcceptTermsHandler::class)(new AcceptTermsCommand($user));

        $version = self::getContainer()->getParameter('app.terms.version');
        self::assertIsString($version);

        $record = $this->audit->record('account.terms.accepted');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $user->id, 'version' => $version], $record->context);
        $this->assertUserSubject($record->subject, $user);

        self::assertSame(['account.terms.accepted'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_updating_a_profile_records_the_account_and_never_the_new_name(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-profile@example.com');

        $this->handler(UpdateProfileHandler::class)(new UpdateProfileCommand($user, 'A Named Person'));

        $record = $this->audit->record('account.profile.updated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        $this->assertUserSubject($record->subject, $user);

        self::assertSame(['account.profile.updated'], $this->audit->domainLogLines());
        self::assertStringNotContainsString(
            'A Named Person',
            json_encode($this->audit->domainChannel->records, \JSON_THROW_ON_ERROR),
        );
    }

    /** Same reasoning as the admin update: an unchanged name is not a transition. */
    public function test_a_profile_update_that_changes_nothing_records_nothing(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-profile-noop@example.com');

        $this->handler(UpdateProfileHandler::class)(new UpdateProfileCommand($user, 'Audit User'));

        self::assertSame([], $this->audit->operations());
        self::assertSame('Audit User', $user->fullName);
    }

    /** Trimming is what decides, so surrounding whitespace is still no change. */
    public function test_a_profile_update_that_only_adds_whitespace_records_nothing(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-profile-space@example.com');

        $this->handler(UpdateProfileHandler::class)(new UpdateProfileCommand($user, '  Audit User  '));

        self::assertSame([], $this->audit->operations());
    }

    public function test_a_completed_password_reset_is_recorded(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-reset@example.com');
        $token = $user->generatePasswordResetToken();
        $this->em->flush();

        $this->handler(ResetPasswordHandler::class)(new ResetPasswordCommand($token, 'AnotherSecret1!'));

        $record = $this->audit->record('account.password.reset');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        $this->assertUserSubject($record->subject, $user);
    }

    /** An unknown token changes no password, so it states nothing. */
    public function test_an_invalid_reset_token_records_nothing(): void
    {
        $this->boot();

        self::assertNull(
            $this->handler(ResetPasswordHandler::class)(new ResetPasswordCommand('not-a-token', 'AnotherSecret1!')),
        );
        self::assertSame([], $this->audit->operations());
    }

    public function test_a_reset_request_records_only_where_a_user_resolved(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-reset-request@example.com');
        $handler = $this->handler(RequestPasswordResetHandler::class);

        $handler(new RequestPasswordResetCommand('audit-reset-request@example.com'));

        $record = $this->audit->record('account.password_reset.requested');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        $this->assertUserSubject($record->subject, $user);
        self::assertStringNotContainsString(
            'audit-reset-request@example.com',
            json_encode($this->audit->domainChannel->records, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The anti-enumeration policy holds through the audit trail: an unknown
     * address and a still-live token both produce no record at all, so the
     * trail cannot answer a question the response refuses to.
     */
    public function test_no_reset_record_distinguishes_an_unknown_address(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-reset-silent@example.com');
        $user->generatePasswordResetToken();
        $this->em->flush();
        $handler = $this->handler(RequestPasswordResetHandler::class);

        $handler(new RequestPasswordResetCommand('nobody-at-all@example.com'));
        $handler(new RequestPasswordResetCommand('audit-reset-silent@example.com'));

        self::assertSame([], $this->audit->operations());
    }

    /**
     * The sender clears the token and never flushes when the enqueue throws,
     * so no reset was issued and the trail must not claim one. The `expects`
     * is the guard: without it this passes on a run that never sent at all.
     */
    public function test_a_reset_request_records_nothing_when_the_send_fails(): void
    {
        $this->boot();
        $this->seedUser('audit-reset-failed@example.com');

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $sender = $this->createMock(PasswordResetEmailSender::class);
        $sender->expects($this->once())
            ->method('send')
            ->willThrowException(new \RuntimeException('mail transport unavailable'));

        new RequestPasswordResetHandler($users, $sender, $this->audit->auditor)(
            new RequestPasswordResetCommand('audit-reset-failed@example.com'),
        );

        self::assertSame([], $this->audit->operations());
        self::assertSame([], $this->audit->domainLogLines());
    }

    public function test_a_resent_verification_email_records_only_where_a_user_resolved(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-resend@example.com');
        $handler = $this->handler(ResendVerificationEmailHandler::class);

        $handler(new ResendVerificationEmailCommand('nobody-at-all@example.com'));
        $handler(new ResendVerificationEmailCommand('audit-resend@example.com'));

        $record = $this->audit->record('account.email_verification.resent');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        $this->assertUserSubject($record->subject, $user);
    }

    /** Pairs with the operator-side record, on the same category. */
    public function test_verifying_an_email_by_link_is_recorded_on_the_security_category(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-verify@example.com');
        $token = $user->generateEmailVerificationToken();
        $this->em->flush();

        $this->handler(VerifyEmailHandler::class)(new VerifyEmailCommand($token));

        $record = $this->audit->record('account.user.email_verified');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        $this->assertUserSubject($record->subject, $user);

        self::assertSame(['account.user.email_verified'], $this->audit->securityLogLines());
        self::assertSame([], $this->audit->domainLogLines());
    }

    public function test_an_invalid_verification_token_records_nothing(): void
    {
        $this->boot();

        self::assertNull($this->handler(VerifyEmailHandler::class)(new VerifyEmailCommand('not-a-token')));
        self::assertSame([], $this->audit->operations());
    }

    public function test_the_operator_verification_is_recorded_on_the_security_category(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-operator-verify@example.com');

        $this->handler(MarkEmailVerifiedHandler::class)(new MarkEmailVerifiedCommand('audit-operator-verify@example.com'));

        $record = $this->audit->record('account.user.email_verified_by_operator');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        $this->assertUserSubject($record->subject, $user);

        self::assertSame(['account.user.email_verified_by_operator'], $this->audit->securityLogLines());
    }

    public function test_revoking_a_live_link_on_a_verified_account_is_recorded_on_its_own(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-operator-revoke@example.com');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->generateEmailVerificationToken();
        $this->em->flush();

        $this->handler(MarkEmailVerifiedHandler::class)(new MarkEmailVerifiedCommand('audit-operator-revoke@example.com'));

        self::assertSame(
            ['account.user.verification_token_revoked_by_operator'],
            $this->audit->operations(),
        );
        $record = $this->audit->record('account.user.verification_token_revoked_by_operator');
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
    }

    public function test_a_promotion_is_recorded_against_the_promoted_account(): void
    {
        $this->boot();
        $user = $this->seedUser('audit-promote@example.com');

        $this->handler(PromoteUserToAdminHandler::class)(new PromoteUserToAdminCommand('audit-promote@example.com'));

        $record = $this->audit->record('account.user.promoted_to_admin');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        $this->assertUserSubject($record->subject, $user);

        self::assertSame(['account.user.promoted_to_admin'], $this->audit->domainLogLines());
    }

    /** An account that already has the role changes nothing, so it states nothing. */
    public function test_promoting_an_existing_admin_records_nothing(): void
    {
        $this->boot();
        $this->seedUser('audit-promote-twice@example.com', ['ROLE_ADMIN']);

        $this->handler(PromoteUserToAdminHandler::class)(new PromoteUserToAdminCommand('audit-promote-twice@example.com'));

        self::assertSame([], $this->audit->operations());
    }

    public function test_a_console_created_admin_is_recorded(): void
    {
        $this->boot();

        $result = $this->handler(CreateAdminUserHandler::class)(new CreateAdminUserCommand(
            email: 'audit-console-admin@example.com',
            plainPassword: 'SecurePassword1!',
            fullName: 'Console Admin',
        ));

        self::assertTrue($result->created);
        $record = $this->audit->record('account.admin.created_from_console');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $result->user->id], $record->context);
        $this->assertUserSubject($record->subject, $result->user);
    }

    public function test_these_handlers_keep_no_logger_beside_the_auditor(): void
    {
        foreach ([
            AcceptTermsHandler::class,
            UpdateProfileHandler::class,
            ResetPasswordHandler::class,
            RequestPasswordResetHandler::class,
            ResendVerificationEmailHandler::class,
            VerifyEmailHandler::class,
            MarkEmailVerifiedHandler::class,
            PromoteUserToAdminHandler::class,
            CreateAdminUserHandler::class,
        ] as $class) {
            DirectLogging::assertRemovedFrom($class);
        }
    }

    private function assertUserSubject(?AuditSubject $subject, User $user): void
    {
        self::assertNotNull($subject);
        self::assertSame('user', $subject->type);
        self::assertSame((string) $user->id, $subject->id);
    }

    private function boot(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function handler(string $class): object
    {
        $handler = self::getContainer()->get($class);
        self::assertInstanceOf($class, $handler);

        return $handler;
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(string $email, array $roles = []): User
    {
        $user = new User(fullName: 'Audit User', email: $email, password: 'irrelevant-hash');
        $user->roles = $roles;
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
