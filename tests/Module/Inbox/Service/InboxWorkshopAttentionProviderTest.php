<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Service\InboxWorkshopAttentionProvider;
use App\Module\Project\Workshop\WorkshopAttentionItem;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxWorkshopAttentionProviderTest extends KernelTestCase
{
    use InboxFixtures;

    public function test_the_six_oldest_open_items_show_oldest_first_and_a_wait_item_is_about_loupe(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $this->project($em, $this->owner($em, 'workshop-attention'), 'workshop-attention');
        $this->item($em, $project, 1, 'Closed')->state = InboxItemState::Done;
        $em->persist(new InboxItem(project: $project, number: 2, kind: InboxItemKind::Wait, title: '#4 Ship it', blocking: true));
        foreach (range(3, 8) as $number) {
            $this->item($em, $project, $number, 'Item '.$number);
        }
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $provider = self::getContainer()->get(InboxWorkshopAttentionProvider::class);
        self::assertInstanceOf(InboxWorkshopAttentionProvider::class, $provider);

        $attention = $provider->forProject($project);

        self::assertSame(
            ['#4 Ship it', 'Item 3', 'Item 4', 'Item 5', 'Item 6', 'Item 7'],
            array_map(static fn (WorkshopAttentionItem $item): string => $item->title, $attention),
        );
        self::assertSame(
            ['loupe', 'agent', 'agent', 'agent', 'agent', 'agent'],
            array_map(static fn (WorkshopAttentionItem $item): string => $item->subject, $attention),
        );
    }
}
