<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Admin;

use App\Module\Account\Command\Admin\InviteOldestWaitlistCommand;
use App\Module\Account\Command\Admin\InviteOldestWaitlistHandler;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InviteOldestWaitlistHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InviteOldestWaitlistHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $handler = $container->get(InviteOldestWaitlistHandler::class);
        self::assertInstanceOf(InviteOldestWaitlistHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_invites_the_oldest_uninvited_entries_first(): void
    {
        $older = new WaitlistEntry('older@example.com', new \DateTimeImmutable('-2 days'));
        $newer = new WaitlistEntry('newer@example.com', new \DateTimeImmutable('-1 day'));
        $this->em->persist($older);
        $this->em->persist($newer);
        $this->em->flush();

        $result = ($this->handler)(new InviteOldestWaitlistCommand(1));

        self::assertSame(1, $result->invited);
        $this->em->clear();
        $entries = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $entries);
        self::assertNotNull($entries->findOneByEmail('older@example.com')?->invitedAt);
        self::assertNull($entries->findOneByEmail('newer@example.com')?->invitedAt);
    }

    public function test_an_expired_unconverted_invite_is_eligible_for_re_invitation(): void
    {
        $expired = new WaitlistEntry('expired-reinvite@example.com');
        $expired->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));
        $this->em->persist($expired);
        $this->em->flush();

        $result = ($this->handler)(new InviteOldestWaitlistCommand(10));

        self::assertSame(1, $result->invited);
        $this->em->clear();
        $entries = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $entries);
        $reloaded = $entries->findOneByEmail('expired-reinvite@example.com');
        self::assertNotNull($reloaded);
        // A fresh token was issued: the new expiry is in the future, not the
        // original expired one.
        self::assertGreaterThan(new \DateTimeImmutable(), $reloaded->inviteExpiresAt);
    }

    public function test_a_converted_entry_is_never_reissued_even_if_expired(): void
    {
        $converted = new WaitlistEntry('converted-expired@example.com');
        $converted->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));
        $converted->markConverted();
        $this->em->persist($converted);
        $this->em->flush();

        $result = ($this->handler)(new InviteOldestWaitlistCommand(10));

        self::assertSame(0, $result->invited);
        self::assertSame(0, $result->skipped);
    }

    public function test_an_actively_invited_unexpired_entry_is_not_reissued(): void
    {
        $active = new WaitlistEntry('active-invite@example.com');
        $active->issueInviteToken();
        $this->em->persist($active);
        $this->em->flush();

        $result = ($this->handler)(new InviteOldestWaitlistCommand(10));

        self::assertSame(0, $result->invited);
        self::assertSame(0, $result->skipped);
    }
}
