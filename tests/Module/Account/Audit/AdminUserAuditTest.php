<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Audit;

use App\Module\Account\Command\Admin\DeleteUserCommand;
use App\Module\Account\Command\Admin\DeleteUserHandler;
use App\Module\Account\Command\Admin\SuspendUserCommand;
use App\Module\Account\Command\Admin\SuspendUserHandler;
use App\Module\Account\Command\Admin\UnsuspendUserCommand;
use App\Module\Account\Command\Admin\UnsuspendUserHandler;
use App\Module\Account\Command\Admin\UpdateUserCommand;
use App\Module\Account\Command\Admin\UpdateUserHandler;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The four admin handlers already logged an actor beside a target. The actor
 * now comes off the security token, so the context names the account acted on
 * and nothing else.
 */
final class AdminUserAuditTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordingAuditor $audit;

    public function test_a_suspension_records_only_whether_a_reason_was_given(): void
    {
        $this->boot();
        $handler = $this->handler(SuspendUserHandler::class);
        $actor = $this->seedUser('audit-suspend-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('audit-suspend-target@example.com');

        $handler(new SuspendUserCommand($target, $actor, 'Repeated spam from a named customer'));

        $record = $this->audit->record('account.admin_user_suspended');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(
            ['userId' => (string) $target->id, 'hasReason' => true],
            $record->context,
        );
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $target->id, $record->subject->id);

        self::assertSame(['account.admin_user_suspended'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
        self::assertStringNotContainsString(
            'Repeated spam from a named customer',
            json_encode($this->audit->domainChannel->records, \JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_blank_reason_records_has_reason_false(): void
    {
        $this->boot();
        $handler = $this->handler(SuspendUserHandler::class);
        $actor = $this->seedUser('audit-suspend-blank-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('audit-suspend-blank-target@example.com');

        $handler(new SuspendUserCommand($target, $actor, '   '));

        self::assertSame(
            ['userId' => (string) $target->id, 'hasReason' => false],
            $this->audit->record('account.admin_user_suspended')->context,
        );
    }

    public function test_an_unsuspension_is_recorded_against_the_reinstated_account(): void
    {
        $this->boot();
        $actor = $this->seedUser('audit-unsuspend-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('audit-unsuspend-target@example.com');
        $this->handler(SuspendUserHandler::class)(new SuspendUserCommand($target, $actor, 'why'));
        $this->audit->forget();

        $this->handler(UnsuspendUserHandler::class)(new UnsuspendUserCommand($target, $actor));

        $record = $this->audit->record('account.admin_user_unsuspended');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $target->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $target->id, $record->subject->id);

        self::assertSame(['account.admin_user_unsuspended'], $this->audit->domainLogLines());
    }

    public function test_an_update_records_the_target_and_whether_the_address_changed(): void
    {
        $this->boot();
        $handler = $this->handler(UpdateUserHandler::class);
        $actor = $this->seedUser('audit-update-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('audit-update-target@example.com');

        $handler(new UpdateUserCommand(
            target: $target,
            actor: $actor,
            fullName: 'Renamed Person',
            email: 'audit-update-moved@example.com',
            isAdmin: false,
            isVerified: false,
        ));

        $record = $this->audit->record('account.admin_user_updated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(
            ['userId' => (string) $target->id, 'emailChanged' => true],
            $record->context,
        );
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $target->id, $record->subject->id);

        self::assertSame(['account.admin_user_updated'], $this->audit->domainLogLines());
        self::assertStringNotContainsString(
            'audit-update-moved@example.com',
            json_encode($this->audit->domainChannel->records, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Doctrine flushes an empty unit of work for a resubmitted form, so nothing
     * downstream notices; the record would be the only thing claiming a change.
     */
    public function test_an_update_that_changes_nothing_records_nothing(): void
    {
        $this->boot();
        $handler = $this->handler(UpdateUserHandler::class);
        $actor = $this->seedUser('audit-noop-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('audit-noop-target@example.com');
        $target->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->flush();

        $handler(new UpdateUserCommand(
            target: $target,
            actor: $actor,
            fullName: 'Audit User',
            email: 'audit-noop-target@example.com',
            isAdmin: false,
            isVerified: true,
        ));

        self::assertSame([], $this->audit->operations());
    }

    /** @return iterable<string, array{string, string, bool, bool}> */
    public static function singleFieldChanges(): iterable
    {
        yield 'name' => ['Renamed', 'keep@example.com', false, false];
        yield 'email' => ['Audit User', 'moved@example.com', false, false];
        yield 'role' => ['Audit User', 'keep@example.com', true, false];
        yield 'verification' => ['Audit User', 'keep@example.com', false, true];
    }

    #[DataProvider('singleFieldChanges')]
    public function test_changing_one_field_still_records_the_update(
        string $fullName,
        string $email,
        bool $isAdmin,
        bool $isVerified,
    ): void {
        $this->boot();
        $handler = $this->handler(UpdateUserHandler::class);
        $actor = $this->seedUser('audit-one-field-actor-'.md5($fullName.$email).'@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('keep@example.com');

        $handler(new UpdateUserCommand(
            target: $target,
            actor: $actor,
            fullName: $fullName,
            email: $email,
            isAdmin: $isAdmin,
            isVerified: $isVerified,
        ));

        self::assertSame(['account.admin_user_updated'], $this->audit->operations());
    }

    public function test_an_admin_deletion_records_both_the_admin_action_and_the_deletion(): void
    {
        $this->boot();
        $handler = $this->handler(DeleteUserHandler::class);
        $actor = $this->seedUser('audit-delete-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('audit-delete-target@example.com');
        $targetId = (string) $target->id;

        $handler(new DeleteUserCommand($target, $actor, 'audit-delete-target@example.com'));

        self::assertSame(['account.deleted', 'account.admin_user_deleted'], $this->audit->operations());

        $record = $this->audit->record('account.admin_user_deleted');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => $targetId], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame($targetId, $record->subject->id);
    }

    /** The admin is named by the record, so repeating them in the context invites drift. */
    public function test_no_admin_handler_repeats_the_acting_admin_in_its_context(): void
    {
        $this->boot();
        $actor = $this->seedUser('audit-noactor-admin@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('audit-noactor-target@example.com');

        $this->handler(SuspendUserHandler::class)(new SuspendUserCommand($target, $actor, 'why'));
        $this->handler(UnsuspendUserHandler::class)(new UnsuspendUserCommand($target, $actor));

        self::assertNotSame([], $this->audit->operations());
        foreach ($this->audit->sink->events as $event) {
            self::assertArrayNotHasKey('actorId', $event->context);
            self::assertArrayNotHasKey('targetId', $event->context);
            self::assertNotContains((string) $actor->id, $event->context);
        }
    }

    /**
     * The whole log line, not only its message: the Monolog sink is what puts
     * the record back into the stream these handlers used to write to directly,
     * and it must carry the outcome, the channel and the subject with it.
     */
    public function test_the_log_line_the_sink_emits_carries_the_record(): void
    {
        $this->boot();
        $handler = $this->handler(SuspendUserHandler::class);
        $actor = $this->seedUser('audit-sink-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('audit-sink-target@example.com');

        $handler(new SuspendUserCommand($target, $actor, 'why'));

        self::assertCount(1, $this->audit->domainChannel->records);
        $line = $this->audit->domainChannel->records[0];
        self::assertSame('account.admin_user_suspended', $line['message']);
        self::assertSame([
            'userId' => (string) $target->id,
            'hasReason' => true,
            'outcome' => 'success',
            'channel' => 'system',
            'subjectType' => 'user',
            'subjectId' => (string) $target->id,
        ], $line['context']);
    }

    public function test_the_admin_handlers_keep_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(SuspendUserHandler::class);
        DirectLogging::assertRemovedFrom(UnsuspendUserHandler::class);
        DirectLogging::assertRemovedFrom(UpdateUserHandler::class);
        DirectLogging::assertRemovedFrom(DeleteUserHandler::class);
    }

    /**
     * The recording Auditor replaces a container service, which the container
     * refuses once the real one is built — so it goes in before anything that
     * depends on it is resolved.
     */
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
