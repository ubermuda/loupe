<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardListTool;
use App\Module\Project\Entity\Project;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardListToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardListTool $tool;
    private CardCreateTool $createTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardListTool::class);
        self::assertInstanceOf(CardListTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-list-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)();
    }

    public function test_the_board_reads_by_priority_then_position(): void
    {
        $this->boardWith('card-list-order');

        $result = ($this->tool)('backlog');

        self::assertSame(3, $result['total']);
        self::assertSame(
            ['High one', 'Medium one', 'Medium two'],
            array_column($result['cards'], 'title'),
        );
        // The creation order, not the board order, so the number is plainly not
        // the rank the column reads in.
        self::assertSame([3, 1, 2], array_column($result['cards'], 'number'));
    }

    public function test_a_type_filter_narrows_the_board(): void
    {
        $this->boardWith('card-list-type');

        $result = ($this->tool)(type: 'bug');

        self::assertSame(['High one'], array_column($result['cards'], 'title'));
    }

    public function test_a_priority_filter_takes_a_name(): void
    {
        $this->boardWith('card-list-priority');

        $result = ($this->tool)(priority: 'medium');

        self::assertSame(['Medium one', 'Medium two'], array_column($result['cards'], 'title'));
    }

    public function test_a_reporter_filter_narrows_the_board(): void
    {
        $this->boardWith('card-list-reporter');
        ($this->createTool)('Dictated', 'Body', 'feature', 'low', reporter: 'human');
        // Without this the assertion below also passes on a board that stored
        // no human card at all.
        self::assertSame(4, ($this->tool)()['total']);

        $result = ($this->tool)(reporter: 'human');

        self::assertSame(['Dictated'], array_column($result['cards'], 'title'));
    }

    /**
     * The widget writes `reviewer` and an agent may not claim it. A filter that
     * reused the create-side rule would make those cards unlistable.
     */
    public function test_a_reporter_filter_reaches_the_cards_the_widget_raised(): void
    {
        $project = $this->boardWith('card-list-reviewer');
        $this->widgetCard($project, 'From the widget');
        self::assertSame(4, ($this->tool)()['total']);

        $result = ($this->tool)(reporter: 'reviewer');

        self::assertSame(['From the widget'], array_column($result['cards'], 'title'));
    }

    public function test_an_unknown_reporter_is_refused(): void
    {
        $this->boardWith('card-list-bad-reporter');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown reporter "robot". Use one of: human, agent, reviewer.');
        ($this->tool)(reporter: 'robot');
    }

    public function test_done_cards_are_returned_with_no_time_window(): void
    {
        $this->boardWith('card-list-done');
        ($this->createTool)('Finished long ago', 'Body', 'docs', 'low', status: 'done');
        // Without this the assertions below also pass on a board that holds
        // nothing at all.
        self::assertSame(4, ($this->tool)()['total']);

        $result = ($this->tool)('done', full: true);

        self::assertSame(1, $result['total']);
        self::assertSame('Finished long ago', $result['cards'][0]['title']);
        self::assertNotNull($result['cards'][0]['completedAt']);
    }

    public function test_an_unfiltered_read_returns_the_columns_in_board_order(): void
    {
        $this->boardWith('card-list-columns');
        ($this->createTool)('Waiting', 'Body', 'feature', 'high', status: 'next');
        ($this->createTool)('Finished', 'Body', 'docs', 'high', status: 'done');

        $result = ($this->tool)();

        self::assertSame(
            ['High one', 'Medium one', 'Medium two', 'Waiting', 'Finished'],
            array_column($result['cards'], 'title'),
        );
    }

    public function test_an_unfiltered_read_still_sorts_done_by_completion(): void
    {
        $this->boardWith('card-list-done-order');
        // The earlier completion carries the higher priority, so a read that
        // ranked Done by priority would put them the other way round.
        $first = ($this->createTool)('Finished first', 'Body', 'docs', 'high', status: 'done');
        ($this->createTool)('Finished second', 'Body', 'docs', 'low', status: 'done');
        // Two cards created in the same second share a completion instant, which
        // would make the order below arbitrary.
        $this->em->getConnection()->executeStatement(
            "UPDATE board_cards SET completed_at = completed_at - INTERVAL '1 hour' WHERE id = :id",
            ['id' => $first['cardId']],
        );

        $done = array_values(array_filter(
            ($this->tool)()['cards'],
            static fn (array $card): bool => 'done' === $card['status'],
        ));

        self::assertCount(2, $done);
        self::assertSame(['Finished second', 'Finished first'], array_column($done, 'title'));
    }

    public function test_another_projects_cards_are_not_listed(): void
    {
        $this->boardWith('card-list-mine');
        $theirs = $this->makeProject('card-list-theirs');
        $this->actAsMcpTokenBoundTo($theirs);

        $result = ($this->tool)();

        self::assertSame(0, $result['total']);
    }

    public function test_a_row_is_a_summary_by_default(): void
    {
        $this->boardWith('card-list-summary');

        $row = ($this->tool)()['cards'][0];

        self::assertSame(
            ['cardId', 'number', 'title', 'type', 'priority', 'status', 'reporter', 'updatedAt'],
            array_keys($row),
        );
    }

    public function test_full_returns_the_body_and_every_link_set(): void
    {
        $this->boardWith('card-list-full');

        $row = ($this->tool)(full: true)['cards'][0];

        self::assertSame('Body', $row['body']);
        self::assertSame([], $row['pullRequests']);
        self::assertSame([], $row['documents']);
        self::assertSame([], $row['siteReviewComments']);
    }

    public function test_a_page_is_cut_from_the_board_and_total_counts_the_whole_set(): void
    {
        $this->boardWith('card-list-paging');

        $first = ($this->tool)(perPage: 2);
        $second = ($this->tool)(page: 2, perPage: 2);

        self::assertSame(['High one', 'Medium one'], array_column($first['cards'], 'title'));
        self::assertSame(3, $first['total']);
        self::assertTrue($first['hasMore']);

        self::assertSame(['Medium two'], array_column($second['cards'], 'title'));
        self::assertSame(3, $second['total']);
        self::assertFalse($second['hasMore']);
    }

    public function test_a_page_past_the_end_is_empty_rather_than_an_error(): void
    {
        $this->boardWith('card-list-overrun');

        $result = ($this->tool)(page: 99);

        self::assertSame([], $result['cards']);
        self::assertSame(3, $result['total']);
        self::assertFalse($result['hasMore']);
    }

    public function test_the_largest_page_number_reads_empty_rather_than_overflowing(): void
    {
        $this->boardWith('card-list-overflow');

        $result = ($this->tool)(page: \PHP_INT_MAX);

        self::assertSame([], $result['cards']);
        self::assertSame(3, $result['total']);
        self::assertFalse($result['hasMore']);
    }

    public function test_page_and_per_page_are_clamped_rather_than_refused(): void
    {
        $this->boardWith('card-list-clamp');

        self::assertSame(1, ($this->tool)(page: -4)['page']);
        self::assertSame(1, ($this->tool)(perPage: 0)['perPage']);
        self::assertSame(CardListTool::MAX_PER_PAGE, ($this->tool)(perPage: 500)['perPage']);
    }

    private function boardWith(string $label): Project
    {
        $this->enableBoard();
        $project = $this->makeProject($label);
        $this->actAsMcpTokenBoundTo($project);

        ($this->createTool)('Medium one', 'Body', 'feature', 'medium');
        ($this->createTool)('Medium two', 'Body', 'feature', 'medium');
        ($this->createTool)('High one', 'Body', 'bug', 'high');

        return $project;
    }

    /** The widget's own path, which is the only one that may write a reviewer card. */
    private function widgetCard(Project $project, string $title): void
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        $handler(new CreateCardCommand(
            project: $project,
            title: $title,
            body: 'Body',
            type: CardType::Idea,
            priority: CardPriority::Low,
            reporter: CardReporter::Reviewer,
        ));
    }
}
