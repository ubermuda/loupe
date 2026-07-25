<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Admin;

use App\Module\Account\Command\Admin\InviteWaitlistEntriesCommand;
use App\Module\Account\Command\Admin\InviteWaitlistEntriesHandler;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\WaitlistInviteEmailSender;
use App\Module\Account\Service\WaitlistInviter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;

final class InviteWaitlistEntriesHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InviteWaitlistEntriesHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $handler = $container->get(InviteWaitlistEntriesHandler::class);
        self::assertInstanceOf(InviteWaitlistEntriesHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_invites_fresh_entries_and_skips_already_invited_and_converted(): void
    {
        $fresh = new WaitlistEntry('fresh@example.com');
        $invited = new WaitlistEntry('already-invited@example.com');
        $invited->issueInviteToken();
        $converted = new WaitlistEntry('already-converted@example.com');
        $converted->markConverted();

        $this->em->persist($fresh);
        $this->em->persist($invited);
        $this->em->persist($converted);
        $this->em->flush();

        $result = ($this->handler)(new InviteWaitlistEntriesCommand([
            (string) $fresh->id,
            (string) $invited->id,
            (string) $converted->id,
        ]));

        self::assertSame(1, $result->invited);
        self::assertSame(2, $result->skipped);

        $this->em->clear();
        $entries = self::getContainer()->get(WaitlistEntryRepository::class);
        $reloaded = $entries->findOneByEmail('fresh@example.com');
        self::assertNotNull($reloaded?->invitedAt);
    }

    public function test_duplicate_ids_are_deduped_and_processed_once(): void
    {
        $fresh = new WaitlistEntry('dup-fresh@example.com');
        $this->em->persist($fresh);
        $this->em->flush();
        $id = (string) $fresh->id;

        // Two copies of the same valid id (a repeated checkbox submission)
        // collapse to a single invite — no double-counting either way.
        $result = ($this->handler)(new InviteWaitlistEntriesCommand([$id, $id]));

        self::assertSame(1, $result->invited);
        self::assertSame(0, $result->skipped);
    }

    public function test_a_malformed_id_alongside_a_valid_one_is_skipped(): void
    {
        $fresh = new WaitlistEntry('malformed-mix@example.com');
        $this->em->persist($fresh);
        $this->em->flush();
        $id = (string) $fresh->id;

        $result = ($this->handler)(new InviteWaitlistEntriesCommand([$id, 'not-a-uuid']));

        self::assertSame(1, $result->invited);
        self::assertSame(1, $result->skipped);
    }

    public function test_unknown_but_well_formed_id_is_skipped(): void
    {
        $result = ($this->handler)(new InviteWaitlistEntriesCommand(['01973b6e-0000-7000-8000-000000000000']));

        self::assertSame(0, $result->invited);
        self::assertSame(1, $result->skipped);
    }

    /**
     * Regression test: WaitlistInviter must not use
     * EntityManagerInterface::wrapInTransaction() for the token-issuance step,
     * because that closes the shared EntityManager on any failure — a dead
     * mailbox for the first entry would otherwise break every subsequent
     * entry in the same batch (`lock()`/`flush()` against a closed manager).
     */
    public function test_a_dead_mailbox_does_not_abort_the_rest_of_the_batch(): void
    {
        $failing = new WaitlistEntry('dead-mailbox@example.com');
        $succeeding = new WaitlistEntry('good-mailbox@example.com');
        $this->em->persist($failing);
        $this->em->persist($succeeding);
        $this->em->flush();

        $sender = $this->createMock(WaitlistInviteEmailSender::class);
        $sender->expects($this->exactly(2))->method('send')->willReturnCallback(
            function (WaitlistEntry $entry) use ($failing): void {
                if ($entry->id === $failing->id) {
                    throw new TransportException('mailbox unavailable');
                }
            },
        );
        $inviter = new WaitlistInviter($sender, $this->em, new NullLogger());
        $waitlistEntries = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $waitlistEntries);
        $handler = new InviteWaitlistEntriesHandler($waitlistEntries, $inviter);

        $result = $handler(new InviteWaitlistEntriesCommand([
            (string) $failing->id,
            (string) $succeeding->id,
        ]));

        self::assertSame(1, $result->invited);
        self::assertSame(1, $result->skipped);

        $this->em->clear();
        $entries = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $entries);
        self::assertNull($entries->findOneByEmail('dead-mailbox@example.com')?->invitedAt);
        self::assertNotNull($entries->findOneByEmail('good-mailbox@example.com')?->invitedAt);
    }
}
