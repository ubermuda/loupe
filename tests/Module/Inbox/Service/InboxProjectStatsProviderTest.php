<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxProjectStatsProvider;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxProjectStatsProviderTest extends KernelTestCase
{
    use InboxScenario;

    private EntityManagerInterface $em;
    private InboxProjectStatsProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $provider = self::getContainer()->get(InboxProjectStatsProvider::class);
        self::assertInstanceOf(InboxProjectStatsProvider::class, $provider);
        $this->provider = $provider;
    }

    public function test_it_counts_the_open_items_of_each_project(): void
    {
        $this->setInboxFlag(true);
        $owner = $this->signedUpUser($this->em, 'inbox-stats');
        $busy = $this->inboxProject($this->em, $owner);
        $quiet = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $busy, 1);
        $this->todo($this->em, $busy, 2);
        $this->answered($this->em, $this->question($this->em, $busy, 3));
        $this->answered($this->em, $this->todo($this->em, $quiet, 1), InboxItemState::Done);

        $stats = $this->provider->statsFor([$busy, $quiet]);

        self::assertSame(2, $stats[(string) $busy->id]->openInboxItemCount);
        self::assertArrayNotHasKey((string) $quiet->id, $stats);
    }

    public function test_it_counts_nothing_while_the_inbox_is_off(): void
    {
        $this->setInboxFlag(false);
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-stats-off'));
        $this->question($this->em, $project, 1);

        self::assertSame([], $this->provider->statsFor([$project]));
    }
}
