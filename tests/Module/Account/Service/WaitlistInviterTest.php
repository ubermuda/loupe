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

        // The token-issuance step runs inside Connection::transactional(), not
        // EntityManager::wrapInTransaction() — the latter closes the shared
        // EntityManager on any failure, which would break every remaining
        // entry in a bulk invite (see WaitlistInviter).
        // lock()/refresh()/flush() are stubbed no-ops except for actually
        // invoking the closure — this exercises the inviter's own logic
        // without a real database.
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
}
