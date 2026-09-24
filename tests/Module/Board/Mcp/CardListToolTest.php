<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\ListCardsHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardListTool;
use App\Module\Project\Entity\Project;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
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

    public function test_an_open_column_reads_in_rank_order(): void
    {
        $project = $this->boardWith('card-list-order');
        $this->em->getConnection()->executeStatement(
            "UPDATE board_cards SET position = CASE title WHEN 'Third' THEN 0 WHEN 'First' THEN 1 ELSE 2 END WHERE project_id = :project",
            ['project' => (string) $project->id],
        );

        $result = ($this->tool)('backlog');

        self::assertSame(3, $result['total']);
        self::assertSame(['Third', 'First', 'Second'], array_column($result['cards'], 'title'));
        // The creation order, not the board order, so the number is plainly not
        // the rank the column reads in.
        self::assertSame([3, 1, 2], array_column($result['cards'], 'number'));
    }

    public function test_a_type_filter_narrows_the_board(): void
    {
        $this->boardWith('card-list-type');

        $result = ($this->tool)(type: 'bug');

        self::assertSame(['Third'], array_column($result['cards'], 'title'));
    }

    public function test_a_reporter_filter_narrows_the_board(): void
    {
        $this->boardWith('card-list-reporter');
        ($this->createTool)('Dictated', 'Body', 'feature', reporter: 'human');
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

    /**
     * The row an image without the reporter column wrote. It carries origin
     * alone, and both the filter and the payload have to fall back to it.
     */
    public function test_a_row_with_no_reporter_still_reads_and_filters_by_its_origin(): void
    {
        $this->boardWith('card-list-legacy-row');
        $legacy = ($this->createTool)('Written by an older image', 'Body', 'bug', reporter: 'human');
        $this->clearStoredReporter($legacy['cardId']);

        $result = ($this->tool)(reporter: 'human');

        self::assertSame(['Written by an older image'], array_column($result['cards'], 'title'));
        self::assertSame('human', $result['cards'][0]['reporter']);
    }

    public function test_an_unknown_reporter_is_refused(): void
    {
        $this->boardWith('card-list-bad-reporter');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown reporter "robot". Use one of: human, agent, reviewer, system.');
        ($this->tool)(reporter: 'robot');
    }

    public function test_an_unknown_status_names_the_columns_of_the_board(): void
    {
        $this->boardWith('card-list-bad-status');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown status "shipped". Use one of: backlog, next, in-progress, done.');
        ($this->tool)('shipped');
    }

    public function test_the_columns_of_the_board_come_back_beside_the_cards_in_both_shapes(): void
    {
        $this->boardWith('card-list-column-list');
        $expected = [
            ['slug' => 'backlog', 'label' => 'Backlog', 'terminal' => false, 'default' => true],
            ['slug' => 'next', 'label' => 'Next', 'terminal' => false, 'default' => false],
            ['slug' => 'in-progress', 'label' => 'In progress', 'terminal' => false, 'default' => false],
            ['slug' => 'done', 'label' => 'Done', 'terminal' => true, 'default' => false],
        ];

        $summary = ($this->tool)();
        $full = ($this->tool)(full: true);
        $filtered = ($this->tool)('done');

        self::assertSame(['cards', 'columns', 'page', 'perPage', 'total', 'hasMore'], array_keys($summary));
        self::assertSame($expected, $summary['columns']);
        self::assertSame(['cards', 'columns', 'page', 'perPage', 'total', 'hasMore'], array_keys($full));
        self::assertSame('Body', $full['cards'][0]['body']);
        self::assertSame($expected, $full['columns']);
        // The filter narrows the cards, and the column list still names every column.
        self::assertSame(0, $filtered['total']);
        self::assertSame($expected, $filtered['columns']);
    }

    public function test_done_cards_are_returned_with_no_time_window(): void
    {
        $this->boardWith('card-list-done');
        ($this->createTool)('Finished long ago', 'Body', 'docs', status: 'done');
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
        ($this->createTool)('Waiting', 'Body', 'feature', status: 'next');
        ($this->createTool)('Finished', 'Body', 'docs', status: 'done');

        $result = ($this->tool)();

        self::assertSame(
            ['First', 'Second', 'Third', 'Waiting', 'Finished'],
            array_column($result['cards'], 'title'),
        );
    }

    public function test_an_unfiltered_read_still_sorts_done_by_completion(): void
    {
        $this->boardWith('card-list-done-order');
        $first = ($this->createTool)('Finished first', 'Body', 'docs', status: 'done');
        ($this->createTool)('Finished second', 'Body', 'docs', status: 'done');
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
            ['cardId', 'number', 'title', 'type', 'status', 'reporter', 'parentCardId', 'updatedAt'],
            array_keys($row),
        );
    }

    public function test_a_summary_row_names_the_parent_by_id(): void
    {
        $this->boardWith('card-list-summary-parent');
        $epic = ($this->createTool)('Epic', 'Body', 'epic');
        $child = ($this->createTool)('Child', 'Body', 'feature', parentCardId: $epic['cardId']);

        $rows = array_column(($this->tool)()['cards'], 'parentCardId', 'cardId');

        self::assertSame($epic['cardId'], $rows[$child['cardId']]);
        self::assertNull($rows[$epic['cardId']]);
    }

    public function test_a_summary_row_reads_the_parent_id_without_loading_the_parent(): void
    {
        $this->boardWith('card-list-summary-parent-lazy');
        $epic = ($this->createTool)('Epic', 'Body', 'epic');
        ($this->createTool)('Child', 'Body', 'feature', parentCardId: $epic['cardId']);
        $this->em->clear();
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        // The epic is filtered out, so its row never loads with the page.
        $rows = ($this->tool)(type: 'feature')['cards'];

        $statements = array_merge(...array_values($queries->getData()));
        self::assertNotEmpty($statements);
        self::assertSame([], array_values(array_filter($statements, static fn (array $query): bool => 1 === preg_match('/FROM board_cards t0 WHERE t0\.id = \?/', (string) $query['sql']))));
        self::assertContains($epic['cardId'], array_column($rows, 'parentCardId'));
    }

    public function test_a_parent_filter_reads_the_children_of_one_epic(): void
    {
        $this->boardWith('card-list-parent-filter');
        $epic = ($this->createTool)('Epic', 'Body', 'epic');
        $otherEpic = ($this->createTool)('Other epic', 'Body', 'epic');
        ($this->createTool)('Child one', 'Body', 'feature', parentCardId: $epic['cardId']);
        ($this->createTool)('Child two', 'Body', 'bug', status: 'done', parentCardId: $epic['cardId']);
        ($this->createTool)('Stranger', 'Body', 'feature', parentCardId: $otherEpic['cardId']);

        $result = ($this->tool)(parentCardId: $epic['cardId']);

        self::assertSame(['Child one', 'Child two'], array_column($result['cards'], 'title'));
        self::assertSame(2, $result['total']);
        self::assertSame(['Child two'], array_column(($this->tool)(type: 'bug', parentCardId: $epic['cardId'])['cards'], 'title'));
    }

    public function test_a_parent_filter_of_another_project_is_refused(): void
    {
        $this->boardWith('card-list-parent-elsewhere');
        $elsewhere = ($this->createTool)('Epic', 'Body', 'epic');
        $this->boardWith('card-list-parent-here');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(\sprintf('Card "%s" not found or not accessible.', $elsewhere['cardId']));
        ($this->tool)(parentCardId: $elsewhere['cardId']);
    }

    public function test_full_reads_the_parent_the_lane_the_children_and_the_progress(): void
    {
        $this->boardWith('card-list-full-epic');
        $epic = ($this->createTool)('Epic', 'Body', 'epic', laneEnabled: false);
        $child = ($this->createTool)('Child', 'Body', 'feature', status: 'done', parentCardId: $epic['cardId']);

        $rows = array_column(($this->tool)(full: true)['cards'], null, 'cardId');

        self::assertSame(['done' => 1, 'total' => 1], $rows[$epic['cardId']]['progress']);
        self::assertSame([['cardId' => $child['cardId'], 'number' => $child['number'], 'title' => 'Child', 'status' => 'done']], $rows[$epic['cardId']]['children']);
        self::assertFalse($rows[$epic['cardId']]['laneEnabled']);
        // Its only child is done, so the epic closed with it.
        self::assertSame(['cardId' => $epic['cardId'], 'number' => $epic['number'], 'title' => 'Epic', 'status' => 'done'], $rows[$child['cardId']]['parent']);
        self::assertNull($rows[$child['cardId']]['progress']);
        self::assertSame([], $rows[$child['cardId']]['children']);
    }

    public function test_a_full_page_reads_the_children_of_every_epic_in_one_query(): void
    {
        $this->boardWith('card-list-children-batch-one');
        $this->epicsWithChildren(1);
        $forOne = $this->childQueriesOfAFullPage();

        $this->boardWith('card-list-children-batch-many');
        $this->epicsWithChildren(4);
        $forMany = $this->childQueriesOfAFullPage();

        self::assertCount(1, $forOne['children']);
        self::assertCount(1, $forMany['children'], "The children query ran once per epic:\n".implode("\n", $forMany['children']));
        // The parent of each child is an epic on the same page, so no card loads on its own.
        self::assertSame([], array_values(array_filter($forMany['all'], static fn (string $sql): bool => 1 === preg_match('/FROM board_cards t0 WHERE t0\.id = \?/', $sql))));
    }

    public function test_full_returns_the_body_and_every_link_set(): void
    {
        $this->boardWith('card-list-full');

        $row = ($this->tool)(full: true)['cards'][0];

        self::assertSame('Body', $row['body']);
        self::assertSame([], $row['pullRequests']);
        self::assertSame([], $row['documents']);
        self::assertSame([], $row['siteReviewComments']);
        self::assertSame([], $row['relatedCards']);
    }

    public function test_full_reads_each_card_links_from_its_own_side(): void
    {
        $this->boardWith('card-list-full-links');
        $cards = ($this->tool)()['cards'];
        ($this->createTool)('Fourth', 'Body', 'feature', relatedCards: [['cardId' => $cards[0]['cardId'], 'kind' => 'blocks']]);

        $rows = array_column(($this->tool)(full: true)['cards'], 'relatedCards', 'title');

        self::assertSame([['cardId' => $cards[0]['cardId'], 'number' => 1, 'title' => 'First', 'status' => 'backlog', 'kind' => 'blocks']], $rows['Fourth']);
        self::assertSame('blocked-by', $rows['First'][0]['kind']);
        self::assertSame('Fourth', $rows['First'][0]['title']);
        self::assertSame([], $rows['Second']);
    }

    public function test_a_page_is_cut_from_the_board_and_total_counts_the_whole_set(): void
    {
        $this->boardWith('card-list-paging');

        $first = ($this->tool)(perPage: 2);
        $second = ($this->tool)(page: 2, perPage: 2);

        self::assertSame(['First', 'Second'], array_column($first['cards'], 'title'));
        self::assertSame(3, $first['total']);
        self::assertTrue($first['hasMore']);

        self::assertSame(['Third'], array_column($second['cards'], 'title'));
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
        self::assertSame(ListCardsHandler::MAX_PER_PAGE, ($this->tool)(perPage: 500)['perPage']);
    }

    private function boardWith(string $label): Project
    {
        $this->enableBoard();
        $project = $this->makeProject($label);
        $this->actAsMcpTokenBoundTo($project);

        ($this->createTool)('First', 'Body', 'feature');
        ($this->createTool)('Second', 'Body', 'feature');
        ($this->createTool)('Third', 'Body', 'bug');

        return $project;
    }

    private function epicsWithChildren(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $epic = ($this->createTool)('Epic '.$i, 'Body', 'epic');
            ($this->createTool)('Child '.$i, 'Body', 'feature', parentCardId: $epic['cardId']);
        }
        $this->em->clear();
    }

    /**
     * The statements of one full page, and those among them that read children by parent.
     *
     * @return array{all: list<string>, children: list<string>}
     */
    private function childQueriesOfAFullPage(): array
    {
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $cards = ($this->tool)(full: true)['cards'];
        // Guard: every child row must be read, or a count of zero proves nothing.
        self::assertNotEmpty(array_filter(array_column($cards, 'children')));

        $all = [];
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $all[] = (string) $query['sql'];
            }
        }

        return [
            'all' => $all,
            'children' => array_values(array_filter($all, static fn (string $sql): bool => 1 === preg_match('/WHERE.*parent_card_id\s*(IN|=)/s', $sql))),
        ];
    }

    /** Makes a row look like one an image without the reporter column wrote. */
    private function clearStoredReporter(string $cardId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET reporter = NULL WHERE id = :id',
            ['id' => $cardId],
        );
        $this->em->clear();
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
            reporter: CardReporter::Reviewer,
        ));
    }
}
