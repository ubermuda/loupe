<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\ListBacklogCardsCommand;
use App\Module\Board\Command\ListBacklogCardsHandler;
use App\Module\Board\Command\ListBacklogCardsView;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardType;
use App\Module\Board\View\BacklogListQuery;
use App\Module\Board\View\BacklogSort;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ListBacklogCardsHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private ListBacklogCardsHandler $listBacklog;
    private CreateCardHandler $createCard;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $listBacklog = self::getContainer()->get(ListBacklogCardsHandler::class);
        self::assertInstanceOf(ListBacklogCardsHandler::class, $listBacklog);
        $this->listBacklog = $listBacklog;

        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->createCard = $createCard;

        $owner = new User(fullName: 'Riley', email: 'backlog-handler-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_an_unfiltered_list_holds_the_backlog_in_rank_order(): void
    {
        $this->card('First');
        $this->card('Second');
        $this->card('On the board', column: $this->column($this->project, 'next'));

        $view = $this->list();

        self::assertSame(['First', 'Second'], $this->titles($view));
        self::assertSame(2, $view->total);
        self::assertSame(2, $view->filteredTotal);
    }

    public function test_the_search_matches_the_title_and_the_body(): void
    {
        $this->card('Otter in the title');
        $this->card('Plain card', body: 'An otter hides in the body.');
        $this->card('Nothing here');

        $view = $this->list(new BacklogListQuery(search: 'otter'));

        self::assertSame(['Otter in the title', 'Plain card'], $this->titles($view));
        self::assertSame(3, $view->total);
        self::assertSame(2, $view->filteredTotal);
    }

    public function test_the_type_filter_keeps_one_type(): void
    {
        $this->card('A bug', type: CardType::Bug);
        $this->card('A feature');

        $view = $this->list(new BacklogListQuery(type: CardType::Bug));

        self::assertSame(['A bug'], $this->titles($view));
    }

    public function test_the_epic_filter_reads_any_none_or_one_epic(): void
    {
        $epic = $this->card('Big epic', type: CardType::Epic, column: $this->column($this->project, 'next'));
        $other = $this->card('Other epic', type: CardType::Epic, column: $this->column($this->project, 'next'));
        $this->card('Child', parent: $epic);
        $this->card('Other child', parent: $other);
        $this->card('Orphan');

        self::assertSame(['Child', 'Other child', 'Orphan'], $this->titles($this->list()));
        self::assertSame(['Orphan'], $this->titles($this->list(new BacklogListQuery(epic: BacklogListQuery::NO_EPIC))));
        self::assertSame(['Child'], $this->titles($this->list(new BacklogListQuery(epic: (string) $epic->id))));

        $epics = array_map(static fn (Card $card): string => $card->title, $this->list()->epics);
        self::assertSame(['Big epic', 'Other epic'], $epics);
    }

    public function test_each_sort_orders_the_backlog(): void
    {
        $old = $this->card('Old');
        $new = $this->card('New');
        $middle = $this->card('Middle');
        $this->stamp($old, '2026-01-01', '2026-03-01');
        $this->stamp($middle, '2026-01-02', '2026-01-02');
        $this->stamp($new, '2026-01-03', '2026-01-03');

        self::assertSame(['Old', 'New', 'Middle'], $this->titles($this->list(new BacklogListQuery(sort: BacklogSort::Rank))));
        self::assertSame(['New', 'Middle', 'Old'], $this->titles($this->list(new BacklogListQuery(sort: BacklogSort::Newest))));
        self::assertSame(['Old', 'Middle', 'New'], $this->titles($this->list(new BacklogListQuery(sort: BacklogSort::Oldest))));
        self::assertSame(['Old', 'New', 'Middle'], $this->titles($this->list(new BacklogListQuery(sort: BacklogSort::Updated))));
    }

    public function test_the_list_pages_the_matches_and_clamps_a_page_past_the_end(): void
    {
        for ($index = 0; $index < ListBacklogCardsHandler::PER_PAGE + 2; ++$index) {
            $this->card('Bug '.$index, type: CardType::Bug);
        }
        $this->card('Feature');

        $second = $this->list(new BacklogListQuery(page: 2, type: CardType::Bug));
        self::assertSame(['Bug 25', 'Bug 26'], $this->titles($second));
        self::assertSame(2, $second->totalPages);
        self::assertNull($second->clampedPage);

        $past = $this->list(new BacklogListQuery(page: 7, type: CardType::Bug));
        self::assertSame(2, $past->clampedPage);
        self::assertSame([], $past->items);

        $huge = $this->list(new BacklogListQuery(page: \PHP_INT_MAX, type: CardType::Idea));
        self::assertSame([], $huge->items);
        self::assertSame(0, $huge->filteredTotal);
    }

    public function test_the_view_counts_pending_feedback_per_card(): void
    {
        $card = $this->card('With feedback');
        $this->card('Without feedback');
        $this->feedback($card, SiteReviewCommentStatus::Pending);
        $this->feedback($card, SiteReviewCommentStatus::Pending);
        $this->feedback($card, SiteReviewCommentStatus::Resolved);

        $view = $this->list();

        self::assertSame([(string) $card->id => 2], $view->pendingComments);
    }

    public function test_the_next_column_is_the_first_open_column_the_board_draws(): void
    {
        $view = $this->list();

        self::assertInstanceOf(BoardColumn::class, $view->nextColumn);
        self::assertSame('next', $view->nextColumn->slug);
        self::assertNotContains('backlog', array_map(static fn (BoardColumn $column): string => $column->slug, $view->boardColumns));
    }

    private function list(BacklogListQuery $listQuery = new BacklogListQuery()): ListBacklogCardsView
    {
        return ($this->listBacklog)(new ListBacklogCardsCommand($this->column($this->project, 'backlog'), $listQuery));
    }

    /** @return list<string> */
    private function titles(ListBacklogCardsView $view): array
    {
        return array_map(static fn (Card $card): string => $card->title, $view->items);
    }

    private function card(string $title, string $body = '', CardType $type = CardType::Feature, ?BoardColumn $column = null, ?Card $parent = null): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: $body,
            type: $type,
            column: $column,
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
    }

    private function stamp(Card $card, string $createdAt, string $updatedAt): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET created_at = :created, updated_at = :updated WHERE id = :id',
            ['created' => $createdAt, 'updated' => $updatedAt, 'id' => (string) $card->id],
        );
    }

    private function feedback(Card $card, SiteReviewCommentStatus $status): void
    {
        $comment = new SiteReviewComment($this->project, 0, 'Fix this', 'https://app.example/page');
        $comment->status = $status;
        $this->em->persist($comment);
        $this->em->persist(new CardSiteReviewComment($card, $comment));
        $this->em->flush();
    }
}
