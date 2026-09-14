<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxAskExporter;
use App\Module\Inbox\Service\InboxItemExporter;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxExporterTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_each_exporter_writes_its_own_file(): void
    {
        self::assertSame('inbox_items.json', $this->itemExporter()->filename());
        self::assertSame('inbox_asks.json', $this->askExporter()->filename());
    }

    public function test_an_item_carries_every_field_and_its_links(): void
    {
        $owner = $this->owner($this->em, 'inbox-export-item');
        $project = $this->project($this->em, $owner, 'export');
        $card = $this->card($this->em, $project, 7);
        $document = $this->document($this->em, $project);

        $item = new InboxItem(
            project: $project,
            number: 3,
            kind: InboxItemKind::Question,
            title: 'Which column?',
            blocking: true,
            body: 'The card is ready.',
            options: ['next', 'done'],
            multiple: true,
            freeText: true,
            createdAt: new \DateTimeImmutable('2026-09-01 09:00:00'),
        );
        $item->state = InboxItemState::Answered;
        $item->selectedOptions = [1];
        $item->answerText = 'Done, it shipped.';
        $item->closeNote = 'No note';
        $item->updatedAt = new \DateTimeImmutable('2026-09-02 10:00:00');
        $item->closedAt = new \DateTimeImmutable('2026-09-02 10:00:00');
        $item->cards->add(new InboxItemCard($item, $card));
        $item->documents->add(new InboxItemDocument($item, $document));
        $this->em->persist($item);
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->itemExporter()->export($owner), false);

        self::assertSame([[
            'id' => (string) $item->id,
            'project' => $project->name,
            'number' => 3,
            'kind' => 'question',
            'title' => 'Which column?',
            'body' => 'The card is ready.',
            'options' => ['next', 'done'],
            'multiple' => true,
            'freeText' => true,
            'blocking' => true,
            'state' => 'answered',
            'selectedOptions' => [1],
            'answerText' => 'Done, it shipped.',
            'closeNote' => 'No note',
            'createdAt' => new \DateTimeImmutable('2026-09-01 09:00:00')->format(\DateTimeInterface::ATOM),
            'updatedAt' => new \DateTimeImmutable('2026-09-02 10:00:00')->format(\DateTimeInterface::ATOM),
            'closedAt' => new \DateTimeImmutable('2026-09-02 10:00:00')->format(\DateTimeInterface::ATOM),
            'cards' => [(string) $card->id],
            'documents' => [(string) $document->id],
        ]], $rows);
    }

    public function test_an_ask_carries_every_field_and_its_items(): void
    {
        $owner = $this->owner($this->em, 'inbox-export-ask');
        $project = $this->project($this->em, $owner, 'export');
        $card = $this->card($this->em, $project);
        $item = $this->item($this->em, $project);
        $sessionId = Uuid::v4();
        $bridgeId = Uuid::v4();

        $ask = new InboxAsk(project: $project, sessionId: $sessionId, bridgeId: $bridgeId, context: 'Two decisions first', createdAt: new \DateTimeImmutable('2026-09-01 09:00:00'));
        $ask->card = $card;
        $ask->closedAt = new \DateTimeImmutable('2026-09-03 11:00:00');
        $membership = new InboxAskItem($ask, $item, new \DateTimeImmutable('2026-09-01 09:00:01'));
        $membership->readAt = new \DateTimeImmutable('2026-09-03 12:00:00');
        $ask->items->add($membership);
        $this->em->persist($ask);
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->askExporter()->export($owner), false);

        self::assertSame([[
            'id' => (string) $ask->id,
            'project' => $project->name,
            'sessionId' => (string) $sessionId,
            'bridgeId' => (string) $bridgeId,
            'context' => 'Two decisions first',
            'card' => (string) $card->id,
            'createdAt' => new \DateTimeImmutable('2026-09-01 09:00:00')->format(\DateTimeInterface::ATOM),
            'closedAt' => new \DateTimeImmutable('2026-09-03 11:00:00')->format(\DateTimeInterface::ATOM),
            'items' => [[
                'item' => (string) $item->id,
                'addedAt' => new \DateTimeImmutable('2026-09-01 09:00:01')->format(\DateTimeInterface::ATOM),
                'readAt' => new \DateTimeImmutable('2026-09-03 12:00:00')->format(\DateTimeInterface::ATOM),
            ]],
        ]], $rows);
    }

    public function test_the_export_holds_the_owner_rows_only(): void
    {
        $owner = $this->owner($this->em, 'inbox-export-mine');
        $stranger = $this->owner($this->em, 'inbox-export-theirs');
        $mine = $this->project($this->em, $owner, 'mine');
        $theirs = $this->project($this->em, $stranger, 'theirs');
        $this->item($this->em, $mine, 1, 'Mine');
        $this->item($this->em, $theirs, 1, 'Theirs');
        $this->ask($this->em, $mine);
        $this->ask($this->em, $theirs);
        $this->ask($this->em, $theirs);
        $this->em->flush();
        $this->em->clear();

        $items = iterator_to_array($this->itemExporter()->export($owner), false);
        self::assertSame(['Mine'], array_column($items, 'title'));
        self::assertCount(1, iterator_to_array($this->askExporter()->export($owner), false));
        self::assertCount(2, iterator_to_array($this->askExporter()->export($stranger), false));
    }

    private function itemExporter(): InboxItemExporter
    {
        $exporter = self::getContainer()->get(InboxItemExporter::class);
        self::assertInstanceOf(InboxItemExporter::class, $exporter);

        return $exporter;
    }

    private function askExporter(): InboxAskExporter
    {
        $exporter = self::getContainer()->get(InboxAskExporter::class);
        self::assertInstanceOf(InboxAskExporter::class, $exporter);

        return $exporter;
    }
}
