<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Service\WaitlistInviteEmailSender;
use App\Module\Account\Service\WaitlistInviter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WaitlistInviterTest extends TestCase
{
    private WaitlistInviteEmailSender&MockObject $sender;
    private EntityManagerInterface&Stub $em;
    private WaitlistInviter $inviter;

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

        $this->inviter = new WaitlistInviter($this->sender, $this->em, new NullLogger());
    }

    public function test_invites_a_fresh_entry(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $this->sender->expects($this->once())->method('send');

        self::assertTrue($this->inviter->invite($entry));
        self::assertTrue($entry->isInvited());
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
        $inviter = new WaitlistInviter($retrySender, $this->em, new NullLogger());

        self::assertTrue($inviter->invite($entry));
    }
}
