<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\BoardLaneView;
use App\Module\Board\Command\BoardView;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** How the board sorts its cards into epic lanes and the "Other cards" row. */
final class ShowBoardHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private ShowBoardHandler $showBoard;
    private Project $project;
    private int $nextNumber = 1;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $showBoard = self::getContainer()->get(ShowBoardHandler::class);
        self::assertInstanceOf(ShowBoardHandler::class, $showBoard);
        $this->showBoard = $showBoard;

        $owner = new User(fullName: 'Riley', email: 'show-board-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_board_with_no_epic_has_no_lanes_and_no_other_row(): void
    {
        $this->card('Plain', 'backlog');
        $this->card('Finished', 'done');

        $board = $this->board();

        self::assertSame([], $board->lanes);
        self::assertNull($board->otherCards);
        self::assertSame([], $board->progress);
        self::assertSame(['Plain'], $this->titles($board->columns[0]->cards));
        self::assertSame(['Finished'], $this->titles($board->columns[3]->cards));
    }

    public function test_lanes_follow_the_column_order_then_the_rank_of_their_epic(): void
    {
        $this->epic('In next', 'next', 0);
        $this->epic('Backlog second', 'backlog', 1);
        $this->epic('Backlog first', 'backlog', 0);
        $this->epic('Lane off', 'backlog', 2)->laneEnabled = false;
        $this->epic('Done epic', 'done');
        $this->em->flush();

        $board = $this->board();

        self::assertSame(
            ['Backlog first', 'Backlog second', 'In next'],
            array_map(static fn (BoardLaneView $lane): string => (string) $lane->epic?->title, $board->lanes),
        );
    }

    public function test_children_sit_in_their_epic_lane_and_every_other_card_in_the_other_row(): void
    {
        $epic = $this->epic('Epic', 'next');
        $this->child($epic, 'Child in backlog', 'backlog');
        $this->child($epic, 'Child in progress', 'in-progress');
        $this->child($epic, 'Child done', 'done');
        $hidden = $this->epic('Hidden lane', 'in-progress');
        $hidden->laneEnabled = false;
        $this->child($hidden, 'Child of a hidden lane', 'backlog');
        $this->card('Loose', 'backlog');
        $this->em->flush();

        $board = $this->board();

        self::assertCount(1, $board->lanes);
        $lane = $board->lanes[0];
        self::assertSame(['Child in backlog'], $this->cell($lane, 'backlog'));
        self::assertSame([], $this->cell($lane, 'next'));
        self::assertSame(['Child in progress'], $this->cell($lane, 'in-progress'));
        self::assertSame(['Child done'], $this->cell($lane, 'done'));

        $other = $board->otherCards;
        self::assertInstanceOf(BoardLaneView::class, $other);
        self::assertNull($other->epic);
        self::assertSame(['Child of a hidden lane', 'Loose'], $this->cell($other, 'backlog'));
        // An epic with its lane on shows in no cell. One with its lane off is a card.
        self::assertSame([], $this->cell($other, 'next'));
        self::assertSame(['Hidden lane'], $this->cell($other, 'in-progress'));

        // The lane epic is in the next column's count, and the board draws no card for it.
        self::assertSame(1, $board->columns[1]->count);
        self::assertSame([3, 0, 2, 1], array_values($board->shownCounts));
    }

    public function test_a_board_with_no_lane_draws_every_card_it_counts(): void
    {
        $this->epic('Lane off', 'next')->laneEnabled = false;
        $this->card('Plain', 'next');
        $this->em->flush();

        $board = $this->board();

        self::assertSame([0, 2, 0, 0], array_values($board->shownCounts));
    }

    public function test_the_list_view_columns_still_hold_every_card(): void
    {
        $epic = $this->epic('Epic', 'next');
        $this->child($epic, 'Child', 'backlog');
        $this->em->flush();

        $board = $this->board();

        self::assertSame(['Child'], $this->titles($board->columns[0]->cards));
        self::assertSame(['Epic'], $this->titles($board->columns[1]->cards));
    }

    public function test_the_children_of_a_done_epic_leave_the_board(): void
    {
        $done = $this->epic('Done epic', 'done');
        $this->child($done, 'Child of the done epic', 'done');
        $open = $this->epic('Open epic', 'next');
        $this->child($open, 'Done child of the open epic', 'done');
        $this->card('Done without parent', 'done');
        $this->em->flush();

        $board = $this->board();

        $titles = $this->titles($board->columns[3]->cards);
        sort($titles);
        self::assertSame(['Done child of the open epic', 'Done epic', 'Done without parent'], $titles);
        // The history page still holds all four.
        self::assertSame(4, $board->columns[3]->terminalTotal);
    }

    public function test_progress_counts_every_child_of_every_epic_in_one_map(): void
    {
        $epic = $this->epic('Epic', 'next');
        $this->child($epic, 'Open', 'backlog');
        $this->child($epic, 'Done', 'done');
        $old = $this->child($epic, 'Done long ago', 'done');
        $old->completedAt = new \DateTimeImmutable('-30 days');
        $empty = $this->epic('Empty', 'backlog');
        $plain = $this->card('Plain', 'backlog');
        $this->em->flush();

        $board = $this->board();

        self::assertSame(['done' => 2, 'total' => 3], $this->progressOf($board, $epic));
        self::assertSame(['done' => 0, 'total' => 0], $this->progressOf($board, $empty));
        self::assertArrayNotHasKey((string) $plain->id, $board->progress);
    }

    private function board(): BoardView
    {
        $this->em->clear();

        return ($this->showBoard)(new ShowBoardCommand($this->em->find(Project::class, $this->project->id) ?? throw new \LogicException('The project is gone.')));
    }

    /** @return array{done: int, total: int} */
    private function progressOf(BoardView $board, Card $card): array
    {
        $progress = $board->progress[(string) $card->id] ?? null;
        self::assertNotNull($progress);

        return ['done' => $progress->done, 'total' => $progress->total];
    }

    /** @return list<string> the titles in the lane's cell of the column with that slug */
    private function cell(BoardLaneView $lane, string $slug): array
    {
        return $this->titles($lane->cells[(string) $this->column($this->project, $slug)->id] ?? []);
    }

    /**
     * @param list<Card> $cards
     *
     * @return list<string>
     */
    private function titles(array $cards): array
    {
        return array_map(static fn (Card $card): string => $card->title, $cards);
    }

    private function epic(string $title, string $column, int $position = 0): Card
    {
        return $this->card($title, $column, $position, CardType::Epic);
    }

    private function child(Card $epic, string $title, string $column): Card
    {
        $child = $this->card($title, $column);
        $child->parent = $epic;
        $this->em->flush();

        return $child;
    }

    private function card(string $title, string $column, int $position = 0, CardType $type = CardType::Feature): Card
    {
        $boardColumn = $this->column($this->project, $column);
        $card = new Card(
            project: $this->project,
            column: $boardColumn,
            title: $title,
            body: '',
            number: $this->nextNumber++,
            type: $type,
            position: $position,
        );
        if ($boardColumn->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
