<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Admin;

use App\Module\Account\Command\Admin\InviteWaitlistEntriesCommand;
use App\Module\Account\Command\Admin\InviteWaitlistEntriesHandler;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
    }}
