<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Service\WaitlistInviteEmailSender;
use App\Module\Account\Service\WaitlistInviter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;

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
        // wrapInTransaction/lock/refresh are stubbed no-ops except for actually
        // invoking the closure — this exercises the inviter's own logic without
        // a real database (dama would otherwise be the only way to get a real
        // EntityManager, and this test does not need persistence).
        $this->em->method('wrapInTransaction')->willReturnCallback(
            fn (callable $func) => $func(),
        );
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

    /**
     * A mail-transport failure must propagate out of the transaction so
     * `wrapInTransaction()` rolls the DB back — the token issued moments
     * earlier in the same transaction is never committed, so the row stays
     * invitable. `EntityManagerInterface::wrapInTransaction()` only rolls
     * back the *database* state; it cannot un-mutate the in-memory PHP object
     * this stub-based test passed in (a real request would discard that
     * object along with the closed EntityManager). This test therefore
     * verifies the propagation contract the bulk handler depends on (Task 6):
     * the exception surfaces so the caller can count the entry as skipped
     * rather than invited. The row-level "stays invitable" guarantee itself
     * is a code-review item, per the concurrency-testing limitation in
     * project-testing.
     */
    public function test_mail_transport_failure_propagates_instead_of_committing(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $this->sender->expects($this->once())->method('send')->willThrowException(new TransportException('boom'));

        $this->expectException(TransportException::class);

        $this->inviter->invite($entry);
    }

    public function test_retrying_an_uninvited_entry_after_a_failed_attempt_succeeds(): void
    {
        // Simulates the post-rollback state: a fresh load of the same row,
        // still uninvited because the failed attempt's token issue never committed.
        $entry = new WaitlistEntry('a@example.com');
        $this->sender->expects($this->once())->method('send');

        self::assertTrue($this->inviter->invite($entry));
    }
}
