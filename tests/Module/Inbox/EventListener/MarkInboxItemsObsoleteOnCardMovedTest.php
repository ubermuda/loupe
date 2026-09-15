<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Driven through a real card move and a real column delete, so each listener runs where Board dispatches its event. */
final class MarkInboxItemsObsoleteOnCardMovedTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_every_open_item_of_a_card_that_finishes_becomes_obsolete(): void
    {
        $project = $this->persistedProject('obsolete-two-items');
        $card = $this->card($this->em, $project);
        $first = $this->linkedItem($project, 1, $card);
        $second = $this->linkedItem($project, 2, $card);
        $this->em->flush();

        $this->move($card, $project, 'done');

        foreach ([$first, $second] as $item) {
            $fresh = $this->reload($item);
            self::assertSame(InboxItemState::Obsolete, $fresh->state);
            self::assertNotNull($fresh->closedAt);
            self::assertNull($fresh->closeNote);
        }
    }

    public function test_an_item_stays_open_while_one_of_its_cards_is_unfinished(): void
    {
        $project = $this->persistedProject('obsolete-two-cards');
        $finishing = $this->card($this->em, $project, 1);
        $unfinished = $this->card($this->em, $project, 2);
        $item = $this->linkedItem($project, 1, $finishing, $unfinished);
        $this->em->flush();

        $this->move($finishing, $project, 'done');
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);

        $unfinished = $this->em->find(Card::class, $unfinished->id);
        self::assertInstanceOf(Card::class, $unfinished);
        $this->move($unfinished, $project, 'done');
        self::assertSame(InboxItemState::Obsolete, $this->reload($item)->state);
    }

    public function test_an_item_that_is_already_closed_keeps_its_state_and_close_time(): void
    {
        $project = $this->persistedProject('obsolete-closed');
        $card = $this->card($this->em, $project);
        $item = $this->linkedItem($project, 1, $card);
        $closedAt = new \DateTimeImmutable('2026-09-01 12:00:00');
        $item->state = InboxItemState::Withdrawn;
        $item->closeNote = 'Settled';
        $item->closedAt = $closedAt;
        $this->em->flush();

        $this->move($card, $project, 'done');

        $fresh = $this->reload($item);
        self::assertSame(InboxItemState::Withdrawn, $fresh->state);
        self::assertSame('Settled', $fresh->closeNote);
        self::assertEquals($closedAt, $fresh->closedAt);
    }

    /**
     * The row reads open while the loaded item reads closed, which is how an
     * item closed earlier in the same unit of work looks to the query. The
     * listener runs inside the move, so it must skip the item, not abort the move.
     */
    public function test_a_card_move_succeeds_when_a_linked_item_already_reads_closed(): void
    {
        $project = $this->persistedProject('obsolete-stale-closed');
        $card = $this->card($this->em, $project);
        $stale = $this->linkedItem($project, 1, $card);
        $open = $this->linkedItem($project, 2, $card);
        $this->em->flush();
        $stale->state = InboxItemState::Withdrawn;
        $this->em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($stale), 'state', InboxItemState::Withdrawn);

        $this->move($card, $project, 'done');

        $this->em->clear();
        $movedCard = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $movedCard);
        self::assertTrue($movedCard->column->terminal);
        self::assertSame(InboxItemState::Obsolete, $this->reload($open)->state);
    }

    public function test_a_column_delete_that_moves_the_last_unfinished_card_into_a_terminal_column_closes_the_item(): void
    {
        $project = $this->persistedProject('obsolete-column-delete');
        $moving = new Card(project: $project, column: $this->column($project, 'in-progress'), title: 'Moves', body: 'Body', number: 1);
        $this->em->persist($moving);
        $finished = new Card(project: $project, column: $this->column($project, 'done'), title: 'Done', body: 'Body', number: 2);
        $this->em->persist($finished);
        $item = $this->linkedItem($project, 1, $moving, $finished);
        $untouched = $this->linkedItem($project, 2, $this->card($this->em, $project, 3));
        $this->em->flush();

        $handler = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $handler);
        $handler(new DeleteBoardColumnCommand($this->column($project, 'in-progress'), CardReporter::Human, $this->column($project, 'done')));

        self::assertSame(InboxItemState::Obsolete, $this->reload($item)->state);
        self::assertSame(InboxItemState::Open, $this->reload($untouched)->state);
    }

    public function test_a_column_delete_into_an_open_column_leaves_the_item_open(): void
    {
        $project = $this->persistedProject('obsolete-column-delete-open');
        $moving = new Card(project: $project, column: $this->column($project, 'in-progress'), title: 'Moves', body: 'Body', number: 1);
        $this->em->persist($moving);
        $item = $this->linkedItem($project, 1, $moving);
        $this->em->flush();

        $handler = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $handler);
        $handler(new DeleteBoardColumnCommand($this->column($project, 'in-progress'), CardReporter::Human, $this->column($project, 'next')));

        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_move_between_open_columns_leaves_the_item_open(): void
    {
        $project = $this->persistedProject('obsolete-open-move');
        $card = $this->card($this->em, $project);
        $item = $this->linkedItem($project, 1, $card);
        $this->em->flush();

        $this->move($card, $project, 'in-progress');

        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    private function persistedProject(string $slug): Project
    {
        $project = $this->project($this->em, $this->owner($this->em, $slug), $slug);
        $this->em->flush();

        return $project;
    }

    private function linkedItem(Project $project, int $number, Card ...$cards): InboxItem
    {
        $item = $this->item($this->em, $project, $number);
        foreach ($cards as $card) {
            $item->cards->add(new InboxItemCard($item, $card));
        }

        return $item;
    }

    private function move(Card $card, Project $project, string $slug): void
    {
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);

        $handler(new UpdateCardCommand($card, CardReporter::Human, column: $this->column($project, $slug)));
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $fresh = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $fresh);

        return $fresh;
    }
}
