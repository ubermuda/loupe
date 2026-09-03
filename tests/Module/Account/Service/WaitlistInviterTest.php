<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Service\WaitlistInviteEmailSender;
use App\Module\Account\Service\WaitlistInviter;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class WaitlistInviterTest extends TestCase
{
    private WaitlistInviteEmailSender&MockObject $sender;
    private EntityManagerInterface&Stub $em;
    private WaitlistInviter $inviter;
    private RecordingAuditor $audit;

    #[\Override]
    protected function setUp(): void
    {
        $this->sender = $this->createMock(WaitlistInviteEmailSender::class);
        $this->em = $this->createStub(EntityManagerInterface::class);

        // Connection::transactional(), not EntityManager::wrapInTransaction(),
        // which closes the shared EntityManager on failure (see WaitlistInviter).
        // The stubs are no-ops except for invoking the closure, so this exercises
        // the inviter's logic without a database.
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(
            fn (callable $func) => $func(),
        );
        $this->em->method('getConnection')->willReturn($connection);

        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->inviter = new WaitlistInviter($this->sender, $this->em, $this->audit->auditor);
    }

    public function test_invites_a_fresh_entry(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $this->sender->expects($this->once())->method('send');

        self::assertTrue($this->inviter->invite($entry));
        self::assertTrue($entry->isInvited());

        $record = $this->audit->record('account.waitlist_invited');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['entryId' => (string) $entry->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('waitlist_entry', $record->subject->type);
        self::assertSame((string) $entry->id, $record->subject->id);

        self::assertSame(['account.waitlist_invited'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_the_inviter_keeps_no_logger_beside_the_auditor(): void
    {
        $this->sender->expects($this->never())->method('send');

        DirectLogging::assertRemovedFrom(WaitlistInviter::class);
    }

    /**
     * An admin asked for the invite and it did not go out, so the trail says so
     * rather than leaving the entry looking untouched.
     */
    public function test_a_failed_send_is_recorded_as_a_failure(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $this->sender->expects($this->once())->method('send')
            ->willThrowException(new \RuntimeException('transport down'));

        self::assertFalse($this->inviter->invite($entry));

        $record = $this->audit->record('account.waitlist_invite_send_failed');
        self::assertSame(AuditOutcome::Failed, $record->outcome);
        self::assertSame(['entryId' => (string) $entry->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('waitlist_entry', $record->subject->type);

        self::assertSame(['account.waitlist_invite_send_failed'], $this->audit->domainLogLines());
        self::assertStringNotContainsString(
            'transport down',
            json_encode($this->audit->domainChannel->records, \JSON_THROW_ON_ERROR),
        );
    }

    public function test_skips_already_invited_entry(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $entry->issueInviteToken();
        $this->sender->expects($this->never())->method('send');

        self::assertFalse($this->inviter->invite($entry));
    }

    public function test_skips_converted_entry(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $entry->markConverted();
        $this->sender->expects($this->never())->method('send');

        self::assertFalse($this->inviter->invite($entry));
    }

    public function test_enqueue_failure_reverts_the_token_and_reports_a_skip(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $this->sender->expects($this->once())->method('send')->willThrowException(new \RuntimeException('transport down'));

        // No throw: a bulk invite must keep going past one bad entry.
        self::assertFalse($this->inviter->invite($entry));

        // clearInvite() reverts the token synchronously — no need for a
        // simulated reload here, unlike a bare transaction rollback which only
        // reverts the database row, not this in-memory object.
        self::assertFalse($entry->isInvited());
    }

    public function test_retrying_after_a_failed_attempt_succeeds(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $this->sender->expects($this->once())->method('send')->willThrowException(new \RuntimeException('transport down'));
        self::assertFalse($this->inviter->invite($entry));

        $retrySender = $this->createMock(WaitlistInviteEmailSender::class);
        $retrySender->expects($this->once())->method('send');
        $inviter = new WaitlistInviter($retrySender, $this->em, $this->audit->auditor);

        self::assertTrue($inviter->invite($entry));
    }
}
