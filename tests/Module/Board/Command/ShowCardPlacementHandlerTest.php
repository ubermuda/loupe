<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
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
        $this->card('Backlog one', 'backlog', 0);
        $lastBacklog = $this->card('Backlog two', 'backlog', 1);
        $firstNext = $this->card('Next one', 'next', 0);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $firstNext));

        self::assertNull($view->after);
        self::assertSame((string) $lastBacklog->id, $view->rowAfter);
    }

    public function test_the_first_card_of_the_board_has_nothing_before_it(): void
    {
        $this->card('Later', 'in-progress', 0);
        $first = $this->card('First', 'backlog', 0);

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
        $old->completedAt = new \DateTimeImmutable(\sprintf('-%d days', ShowBoardHandler::TERMINAL_WINDOW_DAYS + 1));
        $this->card('Recent', 'done', 0);
        $this->em->flush();

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $old));

        self::assertNull($view->card);
        self::assertNull($view->column);
        self::assertSame(1, $view->counts[(string) $this->column($this->project, 'done')->id]);
    }

    public function test_a_deleted_card_is_gone_and_still_carries_every_column_count(): void
    {
        $this->card('Backlog one', 'backlog', 0);
        $this->card('Backlog two', 'backlog', 1);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, null));

        self::assertNull($view->card);
        self::assertSame([
            (string) $this->column($this->project, 'backlog')->id => 2,
            (string) $this->column($this->project, 'next')->id => 0,
            (string) $this->column($this->project, 'in-progress')->id => 0,
            (string) $this->column($this->project, 'done')->id => 0,
        ], $view->counts);
    }

    public function test_the_counts_match_the_board_page(): void
    {
        $this->card('Backlog', 'backlog', 0);
        $moving = $this->card('Next', 'next', 0);
        $this->card('Recent', 'done', 0);
        $old = $this->card('Old', 'done', 0);
        $old->completedAt = new \DateTimeImmutable('-30 days');
        $this->em->flush();

        $showBoard = self::getContainer()->get(ShowBoardHandler::class);
        self::assertInstanceOf(ShowBoardHandler::class, $showBoard);
        $board = $showBoard(new ShowBoardCommand($this->project));
        $pageCounts = [];
        foreach ($board->columns as $columnView) {
            self::assertInstanceOf(BoardColumnView::class, $columnView);
            $pageCounts[(string) $columnView->column->id] = $columnView->count;
        }

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $moving));

        self::assertSame([1, 1, 0, 1], array_values($pageCounts));
        self::assertSame($pageCounts, $view->counts);
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
        $this->card('Other first', 'backlog', 0);
        $firstChild = $this->child($epic, $this->card('Child first', 'backlog', 1));
        $otherSecond = $this->card('Other second', 'backlog', 2);
        $secondChild = $this->child($epic, $this->card('Child second', 'backlog', 3));

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $secondChild));

        self::assertSame((string) $epic->id, $view->lane);
        self::assertSame((string) $firstChild->id, $view->after);
        self::assertSame((string) $otherSecond->id, $view->rowAfter);
        self::assertFalse($view->laneHead);
    }

    public function test_a_card_outside_every_lane_follows_the_card_before_it_in_the_other_row(): void
    {
        $epic = $this->epic('Epic', 'next');
        $otherFirst = $this->card('Other first', 'backlog', 0);
        $child = $this->child($epic, $this->card('Child', 'backlog', 1));
        $otherSecond = $this->card('Other second', 'backlog', 2);

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
        $epic = $this->card('Epic', 'next', 0, CardType::Epic);
        $child = $this->card('Child', 'next', 1, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $child));

        self::assertSame((string) $epic->id, $view->lane);
        self::assertFalse($view->laneHead);
    }

    public function test_a_card_with_no_parent_sits_in_the_other_lane(): void
    {
        $this->card('Epic', 'next', 0, CardType::Epic);
        $orphan = $this->card('Orphan', 'backlog', 0);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $orphan));

        self::assertSame('other', $view->lane);
    }

    public function test_a_child_of_an_epic_with_its_lane_off_sits_in_the_other_lane(): void
    {
        $this->card('Lane epic', 'next', 0, CardType::Epic);
        $laneOff = $this->card('Epic with no lane', 'next', 1, CardType::Epic);
        $laneOff->laneEnabled = false;
        $this->em->flush();
        $child = $this->card('Child', 'backlog', 0, parent: $laneOff);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $child));

        self::assertSame('other', $view->lane);
        self::assertFalse($view->laneHead);
    }

    public function test_a_card_in_a_lane_follows_the_card_before_it_in_the_same_lane(): void
    {
        $epic = $this->card('Epic', 'next', 0, CardType::Epic);
        $first = $this->card('First child', 'next', 1, parent: $epic);
        $orphan = $this->card('Orphan', 'next', 2);
        $second = $this->card('Second child', 'next', 3, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $second));

        self::assertSame((string) $first->id, $view->after);
        self::assertSame((string) $orphan->id, $view->rowAfter);
    }

    public function test_the_first_card_of_a_lane_skips_the_lane_epic_before_it(): void
    {
        $epic = $this->card('Epic', 'next', 0, CardType::Epic);
        $child = $this->card('Child', 'next', 1, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $child));

        self::assertNull($view->after);
        self::assertSame((string) $epic->id, $view->rowAfter);
    }

    public function test_a_lane_epic_is_a_lane_head(): void
    {
        $this->card('Before', 'next', 0);
        $epic = $this->card('Epic', 'next', 1, CardType::Epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $epic));

        self::assertTrue($view->laneHead);
        self::assertNull($view->after);
        self::assertNotNull($view->progress);
    }

    public function test_a_lane_head_follows_the_lane_before_it_in_board_order(): void
    {
        $this->card('Plain', 'backlog', 0);
        $first = $this->card('First epic', 'backlog', 1, CardType::Epic);
        $second = $this->card('Second epic', 'next', 0, CardType::Epic);
        $third = $this->card('Third epic', 'next', 1, CardType::Epic);

        $firstView = ($this->placement)(new ShowCardPlacementCommand($this->project, $first));
        $thirdView = ($this->placement)(new ShowCardPlacementCommand($this->project, $third));

        self::assertNull($firstView->laneAfter);
        self::assertSame((string) $second->id, $thirdView->laneAfter);
    }

    public function test_a_card_that_is_no_lane_head_follows_no_lane(): void
    {
        $epic = $this->card('Epic', 'backlog', 0, CardType::Epic);
        $child = $this->card('Child', 'next', 0, parent: $epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $child));

        self::assertNull($view->laneAfter);
    }

    public function test_an_epic_in_a_terminal_column_is_no_lane_head(): void
    {
        $this->card('Lane epic', 'next', 0, CardType::Epic);
        $finished = $this->card('Finished epic', 'done', 0, CardType::Epic);

        $view = ($this->placement)(new ShowCardPlacementCommand($this->project, $finished));

        self::assertFalse($view->laneHead);
        self::assertSame('other', $view->lane);
    }

    private function epic(string $title, string $slug): Card
    {
        $epic = $this->card($title, $slug, 0);
        $epic->type = CardType::Epic;
        $this->em->flush();

        return $epic;
    }

    private function child(Card $epic, Card $child): Card
    {
        $child->parent = $epic;
        $this->em->flush();

        return $child;
    }

    private function card(string $title, string $slug, int $position, CardType $type = CardType::Feature, ?Card $parent = null): Card
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
