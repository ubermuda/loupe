<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardListTool;
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

    public function test_done_cards_are_returned_with_no_time_window(): void
    {
        $this->boardWith('card-list-done');
        ($this->createTool)('Finished long ago', 'Body', 'docs', 'low', status: 'done');
        // Without this the assertions below also pass on a board that holds
        // nothing at all.
        self::assertSame(4, ($this->tool)()['total']);

        $result = ($this->tool)('done');

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

    private function boardWith(string $label): void
    {
        $this->enableBoard();
        $project = $this->makeProject($label);
        $this->actAsMcpTokenBoundTo($project);

        ($this->createTool)('Medium one', 'Body', 'feature', 'medium');
        ($this->createTool)('Medium two', 'Body', 'feature', 'medium');
        ($this->createTool)('High one', 'Body', 'bug', 'high');
    }
}
