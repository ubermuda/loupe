<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardColumnCards;
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
        $this->boardCard('Backlog tie one', 'backlog', 0, $created);
        $this->boardCard('Backlog tie two', 'backlog', 0, $created);
        $moved = $this->boardCard('Backlog later', 'backlog', 1);
        $openEpic = $this->boardCard('Open epic', 'backlog', 2, type: CardType::Epic);
        // The next column stays empty, between two columns that hold cards.
        $this->boardCard('In progress one', 'in-progress', 0);
        $this->boardCard('In progress two', 'in-progress', 1);

        $restamped = $this->boardCard('Done recent', 'done', 0, completedAt: $second(3600));
        $finished = $second(7200);
        $this->boardCard('Done tie one', 'done', 0, $created, $finished);
        $this->boardCard('Done tie two', 'done', 0, $created, $finished);
        $doneEpic = $this->boardCard('Done epic', 'done', 0, completedAt: $second(10800), type: CardType::Epic);
        $this->boardCard('Child of the done epic', 'done', 0, completedAt: $second(3600), parent: $doneEpic);
        $this->boardCard('Done too long ago', 'done', 0, completedAt: $second(86400 * (ShowBoardHandler::TERMINAL_WINDOW_DAYS + 1)));
        $this->boardCard('Child of the open epic', 'done', 0, completedAt: $second(14400), parent: $openEpic);

        $this->boardCard('Archived long ago', 'archive', 0, completedAt: $second(86400 * 10));
        $this->boardCard('Archived child of the done epic', 'archive', 0, completedAt: $second(60), parent: $doneEpic);
        $this->boardCard('Icebox', 'icebox', 0);

        // A bulk write the loaded cards do not see: the placement must read the rows.
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE board_cards SET position = -1 WHERE id = :id', ['id' => (string) $moved->id]);
        $connection->executeStatement('UPDATE board_cards SET completed_at = :at WHERE id = :id', ['id' => (string) $restamped->id, 'at' => $second(18000)->format('Y-m-d H:i:s')]);

        [$expected, $counts, $totals] = $this->boardPagePlacements();
        self::assertSame([4, 0, 2, 5, 0, 1], array_values($counts));

        $cards = self::getContainer()->get(CardRepository::class)->findBy(['project' => $this->project]);
        self::assertCount(16, $cards);
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
            self::assertSame($place, [(string) $view->column?->id, $view->after, $view->rowAfter], $card->title);
        }
    }

    /**
     * The board page's reading, column by column in board order.
     *
     * @return array{array<string, array{string, ?string, ?string}>, array<string, int>, array<string, int>}
     */
    private function boardPagePlacements(): array
    {
        $container = self::getContainer();
        $columnCards = $container->get(BoardColumnCards::class);
        $cards = $container->get(CardRepository::class);
        $expected = [];
        $counts = [];
        $totals = [];
        $previousRow = null;
        foreach ($container->get(BoardColumnRepository::class)->findForProject($this->project) as $column) {
            $shown = $columnCards->shown($column);
            $counts[(string) $column->id] = \count($shown);
            if ($column->terminal) {
                $totals[(string) $column->id] = $cards->countInColumn($column);
            }
            $previousInColumn = null;
            foreach ($shown as $card) {
                $expected[(string) $card->id] = [(string) $column->id, $previousInColumn, $previousRow];
                $previousInColumn = $previousRow = (string) $card->id;
            }
        }

        return [$expected, $counts, $totals];
    }

    private function boardCard(
        string $title,
        string $slug,
        int $position,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $completedAt = null,
        ?Card $parent = null,
        CardType $type = CardType::Feature,
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

    private function card(string $title, string $slug, int $position): Card
    {
        $column = $this->column($this->project, $slug);
        $card = new Card(
            project: $this->project,
            column: $column,
            title: $title,
            body: '',
            number: $this->nextNumber++,
            type: CardType::Feature,
            position: $position,
        );
        if ($column->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
