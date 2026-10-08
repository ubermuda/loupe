<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\BoardLaneView;
use App\Module\Board\Command\LaneDeckView;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardLanes;
use App\Module\Board\Service\LaneDecks;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShowCardPlacementHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private ShowCardPlacementHandler $placement;
    private Project $project;
    private int $nextNumber = 1;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $placement = self::getContainer()->get(ShowCardPlacementHandler::class);
        self::assertInstanceOf(ShowCardPlacementHandler::class, $placement);
        $this->placement = $placement;

        $owner = new User(fullName: 'Riley', email: 'board-placement-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->addTriageColumn($this->project);
        $this->em->flush();
    }

    public function test_a_card_follows_the_card_before_it_in_its_column(): void
    {
        $first = $this->card('First', 'next', 0);
        $second = $this->card('Second', 'next', 1);
        $this->card('Third', 'next', 2);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $second));

        self::assertSame($second, $view->card);
        self::assertSame($this->column($this->project, 'next'), $view->column);
        self::assertSame((string) $first->id, $view->after);
        self::assertSame((string) $first->id, $view->rowAfter);
        self::assertSame(0, $view->pendingComments);
    }

    public function test_the_first_card_of_a_column_follows_the_last_card_of_an_earlier_column_in_the_list(): void
    {
        $this->card('Triage one', 'triage', 0);
        $lastTriage = $this->card('Triage two', 'triage', 1);
        $firstNext = $this->card('Next one', 'next', 0);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $firstNext));

        self::assertNull($view->after);
        self::assertSame((string) $lastTriage->id, $view->rowAfter);
    }

    public function test_the_first_card_of_the_board_has_nothing_before_it(): void
    {
        $this->card('Later', 'in-progress', 0);
        $first = $this->card('First', 'triage', 0);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $first));

        self::assertNull($view->after);
        self::assertNull($view->rowAfter);
    }

    public function test_a_terminal_column_reads_newest_first(): void
    {
        $older = $this->card('Older', 'done', 0);
        $older->completedAt = new \DateTimeImmutable('-2 days');
        $newer = $this->card('Newer', 'done', 0);
        $newer->completedAt = new \DateTimeImmutable('-1 day');
        $this->em->flush();

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $older));

        self::assertSame($older, $view->card);
        self::assertSame((string) $newer->id, $view->after);
    }

    public function test_a_terminal_card_outside_the_window_is_gone_and_the_counts_skip_it(): void
    {
        $old = $this->card('Old', 'done', 0);
        $old->completedAt = new \DateTimeImmutable('-4 days');
        $this->card('Recent', 'done', 0);
        $this->em->flush();

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $old));

        self::assertNull($view->card);
        self::assertNull($view->column);
        self::assertSame(1, $view->counts[(string) $this->column($this->project, 'done')->id]);
    }

    public function test_the_board_shows_the_finished_cards_of_the_window_the_project_set(): void
    {
        $settings = new BoardAutomationSettings($this->project);
        $settings->terminalWindowDays = 10;
        $this->em->persist($settings);
        $older = $this->card('Older', 'done', 0);
        $older->completedAt = new \DateTimeImmutable('-5 days');
        $tooOld = $this->card('Too old', 'done', 0);
        $tooOld->completedAt = new \DateTimeImmutable('-11 days');
        $this->em->flush();

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $older));
        self::assertSame($older, $view->card);
        self::assertSame(1, $view->counts[(string) $this->column($this->project, 'done')->id]);

        $showBoard = self::getContainer()->get(ShowBoardHandler::class);
        self::assertInstanceOf(ShowBoardHandler::class, $showBoard);
        $board = $showBoard(new ShowBoardCommand($this->project));
        self::assertSame(10, $board->terminalWindowDays);
        $done = array_find($board->columns, static fn (BoardColumnView $view): bool => 'done' === $view->column->slug);
        self::assertNotNull($done);
        self::assertSame([$older], $done->cards);
    }

    public function test_a_deleted_card_is_gone_and_still_carries_every_column_count(): void
    {
        $this->card('Triage one', 'triage', 0);
        $this->card('Triage two', 'triage', 1);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, null));

        self::assertNull($view->card);
        self::assertSame([
            (string) $this->column($this->project, 'backlog')->id => 0,
            (string) $this->column($this->project, 'triage')->id => 2,
            (string) $this->column($this->project, 'next')->id => 0,
            (string) $this->column($this->project, 'in-progress')->id => 0,
            (string) $this->column($this->project, 'done')->id => 0,
        ], $view->counts);
    }

    public function test_a_card_in_the_backlog_is_not_shown_and_the_counts_carry_the_backlog(): void
    {
        $waiting = $this->card('Waiting', 'backlog', 0);
        $this->card('Waiting too', 'backlog', 1);
        $laneOff = $this->card('Waiting epic with its lane off', 'backlog', 2, 'epic');
        $laneOff->laneEnabled = false;
        $this->em->flush();
        $this->card('Triage one', 'triage', 0);

        foreach ([$waiting, $laneOff] as $card) {
            $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $card));

            self::assertNull($view->card, $card->title);
            self::assertNull($view->column, $card->title);
            self::assertSame(3, $view->counts[(string) $this->column($this->project, 'backlog')->id]);
            self::assertSame(1, $view->counts[(string) $this->column($this->project, 'triage')->id]);
        }
    }

    public function test_a_backlog_child_of_a_lane_epic_names_the_epic_whose_deck_shows_it(): void
    {
        $epic = $this->card('Epic', 'next', 0, 'epic');
        $laneOff = $this->card('Epic with its lane off', 'next', 1, 'epic');
        $laneOff->laneEnabled = false;
        $this->em->flush();
        $waiting = $this->card('Waiting child', 'backlog', 0, parent: $epic);
        $waitingOff = $this->card('Waiting child of the epic with its lane off', 'backlog', 1, parent: $laneOff);
        $orphan = $this->card('Waiting', 'backlog', 2);
        $open = $this->card('Open child', 'triage', 0, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $waiting));

        self::assertNull($view->card);
        self::assertSame((string) $epic->id, $view->deckEpic);
        foreach ([$waitingOff, $orphan, $open] as $card) {
            self::assertNull(($this->placement)(new ShowCardPlacementCommand($this->project, $card))->deckEpic, $card->title);
        }
    }

    public function test_a_lane_epic_in_the_backlog_is_a_lane_head_with_no_list_row(): void
    {
        $this->card('Plain', 'triage', 0);
        $first = $this->card('Waiting epic', 'backlog', 0, 'epic');
        $second = $this->card('Next epic', 'next', 0, 'epic');
        $this->card('Child', 'next', 1, parent: $first);

        $firstView = ($this->placement)(new ShowCardPlacementCommand($this->project, $first));
        $secondView = ($this->placement)(new ShowCardPlacementCommand($this->project, $second));

        self::assertSame($first, $firstView->card);
        self::assertSame($this->column($this->project, 'backlog'), $firstView->column);
        self::assertTrue($firstView->laneHead);
        self::assertNull($firstView->lane);
        self::assertNull($firstView->after);
        self::assertNull($firstView->rowAfter);
        self::assertNull($firstView->laneAfter);
        self::assertSame(1, $firstView->progress?->total);
        self::assertSame((string) $first->id, $secondView->laneAfter);
    }

    public function test_the_counts_match_the_board_page(): void
    {
        $this->card('Triage', 'triage', 0);
        $moving = $this->card('Next', 'next', 0);
        $this->card('Recent', 'done', 0);
        $old = $this->card('Old', 'done', 0);
        $old->completedAt = new \DateTimeImmutable('-30 days');
        $this->em->flush();

        $showBoard = self::getContainer()->get(ShowBoardHandler::class);
        self::assertInstanceOf(ShowBoardHandler::class, $showBoard);
        $board = $showBoard(new ShowBoardCommand($this->project));
        $pageCounts = [(string) $board->backlog->id => $board->backlogCount];
        foreach ($board->columns as $columnView) {
            self::assertInstanceOf(BoardColumnView::class, $columnView);
            $pageCounts[(string) $columnView->column->id] = $columnView->count;
        }

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $moving));

        self::assertSame([0, 1, 1, 0, 1], array_values($pageCounts));
        self::assertSame($pageCounts, $view->counts);
    }

    /**
     * Every card of a board is placed as the board page places it: the page
     * reads each column in full, and the placement must agree card by card.
     */
    public function test_every_card_is_placed_where_the_board_page_shows_it(): void
    {
        $this->em->persist(new BoardColumn($this->project, 'Archive', 'archive', 4, terminal: true));
        $this->em->persist(new BoardColumn($this->project, 'Icebox', 'icebox', 5));
        $this->em->flush();

        $second = static fn (int $secondsAgo): \DateTimeImmutable => new \DateTimeImmutable(date('Y-m-d H:i:s', time() - $secondsAgo));
        $created = $second(86400 * 20);

        // The first card of the board is one of two cards that tie on position and creation.
        $this->boardCard('Triage tie one', 'triage', 0, $created);
        $this->boardCard('Triage tie two', 'triage', 0, $created);
        $moved = $this->boardCard('Triage later', 'triage', 1);
        $openEpic = $this->boardCard('Open epic', 'triage', 2, type: 'epic');
        $this->boardCard('Triage child of the open epic', 'triage', 3, parent: $openEpic);
        $laneOff = $this->boardCard('Epic with its lane off', 'icebox', 1, type: 'epic');
        $laneOff->laneEnabled = false;
        $this->boardCard('Child of the epic with its lane off', 'triage', 4, parent: $laneOff);
        // The next column stays empty, between two columns that hold cards.
        $this->boardCard('In progress one', 'in-progress', 0);
        $this->boardCard('In progress child of the open epic', 'in-progress', 1, parent: $openEpic);
        $this->boardCard('In progress two', 'in-progress', 2);
        $nestedEpic = $this->boardCard('Epic inside the open epic', 'in-progress', 3, parent: $openEpic, type: 'epic');
        $this->boardCard('Child of the nested epic', 'in-progress', 4, parent: $nestedEpic);
        $this->boardCard('Second in progress child of the open epic', 'in-progress', 5, parent: $openEpic);

        $restamped = $this->boardCard('Done recent', 'done', 0, completedAt: $second(3600));
        $finished = $second(7200);
        $this->boardCard('Done tie one', 'done', 0, $created, $finished);
        $this->boardCard('Done tie two', 'done', 0, $created, $finished);
        $doneEpic = $this->boardCard('Done epic', 'done', 0, completedAt: $second(10800), type: 'epic');
        $this->boardCard('Child of the done epic', 'done', 0, completedAt: $second(3600), parent: $doneEpic);
        $this->boardCard('Done too long ago', 'done', 0, completedAt: $second(86400 * 4));
        $this->boardCard('Child of the open epic', 'done', 0, completedAt: $second(14400), parent: $openEpic);
        $this->boardCard('Later child of the open epic', 'done', 0, completedAt: $second(5400), parent: $openEpic);

        $this->boardCard('Archived long ago', 'archive', 0, completedAt: $second(86400 * 10));
        $this->boardCard('Archived child of the done epic', 'archive', 0, completedAt: $second(60), parent: $doneEpic);
        $this->boardCard('Icebox', 'icebox', 0);
        $this->boardCard('Last lane epic', 'icebox', 2, type: 'epic');

        // The board draws no Backlog, and an epic there still draws its lane.
        $this->boardCard('Waiting', 'backlog', 0);
        $waitingEpic = $this->boardCard('Waiting epic', 'backlog', 1, type: 'epic');
        $this->boardCard('In progress child of the waiting epic', 'in-progress', 6, parent: $waitingEpic);
        $this->boardCard('Waiting child of the waiting epic', 'backlog', 2, parent: $waitingEpic);
        $waitingLaneOff = $this->boardCard('Waiting epic with its lane off', 'backlog', 3, type: 'epic');
        $waitingLaneOff->laneEnabled = false;
        // More than a deck holds, so the deck of the waiting epic is a window on its Backlog children.
        for ($i = 0; $i < LaneDecks::DECK_SIZE; ++$i) {
            $this->boardCard('Waiting child '.$i.' of the waiting epic', 'backlog', 10 - $i, parent: $waitingEpic);
        }
        $this->boardCard('Waiting child of the open epic', 'backlog', 4, parent: $openEpic);
        $this->boardCard('Waiting child of the epic with its lane off', 'backlog', 5, parent: $waitingLaneOff);
        $this->em->flush();

        // A bulk write the loaded cards do not see: the placement must read the rows.
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE board_cards SET position = -1 WHERE id = :id', ['id' => (string) $moved->id]);
        $connection->executeStatement('UPDATE board_cards SET completed_at = :at WHERE id = :id', ['id' => (string) $restamped->id, 'at' => $second(18000)->format('Y-m-d H:i:s')]);

        $cards = self::getContainer()->get(CardRepository::class)->findBy(['project' => $this->project]);
        self::assertCount(30 + LaneDecks::DECK_SIZE + 2, $cards);

        $lanes = $this->assertEveryPlacementMatchesTheBoardPage($cards, [14, 6, 0, 7, 6, 0, 3]);
        self::assertCount(4, $lanes);
        self::assertSame((string) $waitingEpic->id, $lanes[0]);
        // Guard: the decks compared above hold cards, and one of them is cut at the deck size.
        $decks = $this->boardPagePlacements()[4];
        self::assertSame([(string) $waitingEpic->id, (string) $openEpic->id], array_keys($decks));
        self::assertCount(LaneDecks::DECK_SIZE, $decks[(string) $waitingEpic->id]->cards);
        self::assertSame(LaneDecks::DECK_SIZE + 1, $decks[(string) $waitingEpic->id]->count);

        foreach ($cards as $card) {
            $card->laneEnabled = false;
        }
        $this->em->flush();

        self::assertSame([], $this->assertEveryPlacementMatchesTheBoardPage($cards, [14, 6, 0, 7, 6, 0, 3]));
    }

    /**
     * @param list<Card> $cards
     * @param list<int>  $columnCounts
     *
     * @return list<string> the lane epics of the board page, in their order
     */
    private function assertEveryPlacementMatchesTheBoardPage(array $cards, array $columnCounts): array
    {
        [$expected, $counts, $totals, $lanes, $decks] = $this->boardPagePlacements();
        self::assertSame($columnCounts, array_values($counts));

        foreach ($cards as $card) {
            $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $card));
            $place = $expected[(string) $card->id] ?? null;

            self::assertSame($counts, $view->counts, $card->title);
            self::assertSame($totals, $view->terminalTotals, $card->title);
            if (null === $place) {
                self::assertNull($view->card, $card->title);
                self::assertNull($view->column, $card->title);
                continue;
            }

            self::assertSame($card, $view->card, $card->title);
            self::assertSame(
                $place,
                [(string) $view->column?->id, $view->after, $view->rowAfter, $view->lane, $view->laneHead, $view->laneAfter],
                $card->title,
            );
            self::assertSame($this->deckFace($decks[(string) $card->id] ?? null), $this->deckFace($view->deck), $card->title);
        }

        return $lanes;
    }

    /**
     * The board page's reading: the list runs column by column, and a board
     * with lanes places each card in the cell of its lane.
     *
     * @return array{array<string, array{string, ?string, ?string, ?string, bool, ?string}>, array<string, int>, array<string, int>, list<string>, array<string, LaneDeckView>}
     */
    private function boardPagePlacements(): array
    {
        $showBoard = self::getContainer()->get(ShowBoardHandler::class);
        self::assertInstanceOf(ShowBoardHandler::class, $showBoard);
        $board = $showBoard(new ShowBoardCommand($this->project));

        $laneEpics = array_map(static fn (BoardLaneView $lane): string => (string) $lane->epic?->id, $board->lanes);
        $laneOf = static function (Card $card) use ($laneEpics): ?string {
            if ([] === $laneEpics) {
                return null;
            }
            $parentId = null === $card->parent ? null : (string) $card->parent->id;

            return null !== $parentId && \in_array($parentId, $laneEpics, true) ? $parentId : BoardLanes::OTHER;
        };

        $afterInCell = [];
        foreach ([...$board->lanes, ...(null === $board->otherCards ? [] : [$board->otherCards])] as $lane) {
            foreach ($lane->cells as $cell) {
                $previous = null;
                foreach ($cell as $card) {
                    $afterInCell[(string) $card->id] = $previous;
                    $previous = (string) $card->id;
                }
            }
        }

        $expected = [];
        $counts = [(string) $board->backlog->id => $board->backlogCount];
        $totals = [];
        $previousRow = null;
        foreach ($board->columns as $columnView) {
            self::assertInstanceOf(BoardColumnView::class, $columnView);
            $columnId = (string) $columnView->column->id;
            $counts[$columnId] = $columnView->count;
            if (null !== $columnView->terminalTotal) {
                $totals[$columnId] = $columnView->terminalTotal;
            }
            $previousInColumn = null;
            foreach ($columnView->cards as $card) {
                $id = (string) $card->id;
                $laneIndex = array_search($id, $laneEpics, true);
                $isLaneHead = \is_int($laneIndex);
                $after = match (true) {
                    $isLaneHead => null,
                    [] === $laneEpics => $previousInColumn,
                    default => $afterInCell[$id],
                };
                $laneAfter = $isLaneHead && $laneIndex > 0 ? $laneEpics[$laneIndex - 1] : null;
                $expected[$id] = [$columnId, $after, $previousRow, $isLaneHead ? null : $laneOf($card), $isLaneHead, $laneAfter];
                $previousInColumn = $previousRow = $id;
            }
        }

        // A lane epic in the Backlog has a lane head and no list row.
        foreach ($laneEpics as $laneIndex => $epicId) {
            $expected[$epicId] ??= [(string) $board->backlog->id, null, null, null, true, $laneEpics[$laneIndex - 1] ?? null];
        }

        return [$expected, $counts, $totals, array_values($laneEpics), $board->decks];
    }

    /** @return array{list<string>, int}|null the card ids and the count of a deck */
    private function deckFace(?LaneDeckView $deck): ?array
    {
        return null === $deck ? null : [array_map(static fn (Card $card): string => (string) $card->id, $deck->cards), $deck->count];
    }

    private function boardCard(
        string $title,
        string $slug,
        int $position,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $completedAt = null,
        ?Card $parent = null,
        string $type = 'feature',
    ): Card {
        $card = new Card(
            project: $this->project,
            column: $this->column($this->project, $slug),
            title: $title,
            body: '',
            number: $this->nextNumber++,
            type: $type,
            position: $position,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
        $card->completedAt = $completedAt;
        $card->parent = $parent;
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    public function test_a_board_with_no_lane_gives_no_lane_key(): void
    {
        $card = $this->card('Alone', 'next', 0);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $card));

        self::assertNull($view->lane);
        self::assertFalse($view->laneHead);
        self::assertNull($view->laneAfter);
    }

    public function test_a_child_follows_the_card_before_it_in_its_lane(): void
    {
        $epic = $this->epic('Epic', 'next');
        $this->card('Other first', 'triage', 0);
        $firstChild = $this->child($epic, $this->card('Child first', 'triage', 1));
        $otherSecond = $this->card('Other second', 'triage', 2);
        $secondChild = $this->child($epic, $this->card('Child second', 'triage', 3));

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $secondChild));

        self::assertSame((string) $epic->id, $view->lane);
        self::assertSame((string) $firstChild->id, $view->after);
        self::assertSame((string) $otherSecond->id, $view->rowAfter);
        self::assertFalse($view->laneHead);
    }

    public function test_a_card_outside_every_lane_follows_the_card_before_it_in_the_other_row(): void
    {
        $epic = $this->epic('Epic', 'next');
        $otherFirst = $this->card('Other first', 'triage', 0);
        $child = $this->child($epic, $this->card('Child', 'triage', 1));
        $otherSecond = $this->card('Other second', 'triage', 2);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $otherSecond));

        self::assertSame('other', $view->lane);
        self::assertSame((string) $otherFirst->id, $view->after);
        self::assertSame((string) $child->id, $view->rowAfter);
    }

    public function test_a_lane_epic_is_a_lane_head_and_not_a_card_of_any_lane(): void
    {
        $epic = $this->epic('Epic', 'next');

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $epic));

        self::assertSame($epic, $view->card);
        self::assertTrue($view->laneHead);
        self::assertNull($view->lane);
    }

    public function test_an_epic_with_its_lane_off_is_a_card_of_the_other_row(): void
    {
        $this->epic('Open epic', 'next');
        $closed = $this->epic('Lane off', 'next');
        $closed->laneEnabled = false;
        $this->em->flush();

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $closed));

        self::assertFalse($view->laneHead);
        self::assertSame('other', $view->lane);
    }

    public function test_a_child_of_a_lane_epic_sits_in_the_lane_of_its_epic(): void
    {
        $epic = $this->card('Epic', 'next', 0, 'epic');
        $child = $this->card('Child', 'next', 1, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $child));

        self::assertSame((string) $epic->id, $view->lane);
        self::assertFalse($view->laneHead);
    }

    public function test_a_card_with_no_parent_sits_in_the_other_lane(): void
    {
        $this->card('Epic', 'next', 0, 'epic');
        $orphan = $this->card('Orphan', 'triage', 0);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $orphan));

        self::assertSame('other', $view->lane);
    }

    public function test_a_child_of_an_epic_with_its_lane_off_sits_in_the_other_lane(): void
    {
        $this->card('Lane epic', 'next', 0, 'epic');
        $laneOff = $this->card('Epic with no lane', 'next', 1, 'epic');
        $laneOff->laneEnabled = false;
        $this->em->flush();
        $child = $this->card('Child', 'triage', 0, parent: $laneOff);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $child));

        self::assertSame('other', $view->lane);
        self::assertFalse($view->laneHead);
    }

    public function test_a_card_in_a_lane_follows_the_card_before_it_in_the_same_lane(): void
    {
        $epic = $this->card('Epic', 'next', 0, 'epic');
        $first = $this->card('First child', 'next', 1, parent: $epic);
        $orphan = $this->card('Orphan', 'next', 2);
        $second = $this->card('Second child', 'next', 3, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $second));

        self::assertSame((string) $first->id, $view->after);
        self::assertSame((string) $orphan->id, $view->rowAfter);
    }

    public function test_the_first_card_of_a_lane_skips_the_lane_epic_before_it(): void
    {
        $epic = $this->card('Epic', 'next', 0, 'epic');
        $child = $this->card('Child', 'next', 1, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $child));

        self::assertNull($view->after);
        self::assertSame((string) $epic->id, $view->rowAfter);
    }

    public function test_a_lane_epic_is_a_lane_head(): void
    {
        $this->card('Before', 'next', 0);
        $epic = $this->card('Epic', 'next', 1, 'epic');

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $epic));

        self::assertTrue($view->laneHead);
        self::assertNull($view->after);
        self::assertNotNull($view->progress);
    }

    public function test_a_lane_head_follows_the_lane_before_it_in_board_order(): void
    {
        $this->card('Plain', 'triage', 0);
        $first = $this->card('First epic', 'triage', 1, 'epic');
        $second = $this->card('Second epic', 'next', 0, 'epic');
        $third = $this->card('Third epic', 'next', 1, 'epic');

        $firstView = ($this->placement)(new ShowCardPlacementCommand($this->project, $first));
        $thirdView = ($this->placement)(new ShowCardPlacementCommand($this->project, $third));

        self::assertNull($firstView->laneAfter);
        self::assertSame((string) $second->id, $thirdView->laneAfter);
    }

    public function test_a_card_that_is_no_lane_head_follows_no_lane(): void
    {
        $epic = $this->card('Epic', 'triage', 0, 'epic');
        $child = $this->card('Child', 'next', 0, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $child));

        self::assertNull($view->laneAfter);
    }

    public function test_an_epic_in_a_terminal_column_is_no_lane_head(): void
    {
        $this->card('Lane epic', 'next', 0, 'epic');
        $finished = $this->card('Finished epic', 'done', 0, 'epic');

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $finished));

        self::assertFalse($view->laneHead);
        self::assertSame('other', $view->lane);
    }

    private function epic(string $title, string $slug): Card
    {
        $epic = $this->card($title, $slug, 0);
        $epic->type = 'epic';
        $this->em->flush();

        return $epic;
    }

    private function child(Card $epic, Card $child): Card
    {
        $child->parent = $epic;
        $this->em->flush();

        return $child;
    }

    private function card(string $title, string $slug, int $position, string $type = 'feature', ?Card $parent = null): Card
    {
        $column = $this->column($this->project, $slug);
        $card = new Card(
            project: $this->project,
            column: $column,
            title: $title,
            body: '',
            number: $this->nextNumber++,
            type: $type,
            position: $position,
        );
        $card->parent = $parent;
        if ($column->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
