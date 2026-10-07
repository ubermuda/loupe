<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Mcp\ColumnReorderTool;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ColumnReorderToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ColumnReorderTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ColumnReorderTool::class);
        self::assertInstanceOf(ColumnReorderTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_columns_take_the_new_order_after_the_backlog(): void
    {
        $project = $this->makeProject('column-reorder');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(['done', 'next', 'in-progress']);

        self::assertSame(['backlog', 'done', 'next', 'in-progress'], array_column($result['columns'], 'slug'));

        $this->em->clear();
        $repository = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $repository);
        self::assertSame(['backlog', 'done', 'next', 'in-progress'], array_map(static fn (BoardColumn $column): string => $column->slug, $repository->findForProject($project)));
    }

    public function test_the_backlog_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-reorder-backlog'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Backlog always comes first. Leave backlog out of order.');
        ($this->tool)(['backlog', 'done', 'next', 'in-progress']);
    }

    public function test_an_unknown_slug_is_refused_with_the_slugs_the_board_has(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-reorder-unknown'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown column "review". Use one of: next, in-progress, done.');
        ($this->tool)(['done', 'review', 'next', 'in-progress']);
    }

    public function test_a_missing_slug_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-reorder-missing'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Name each of these columns exactly once: next, in-progress, done.');
        ($this->tool)(['done', 'next']);
    }

    public function test_a_repeated_slug_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-reorder-twice'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Name each of these columns exactly once');
        ($this->tool)(['done', 'next', 'next', 'in-progress']);
    }

    public function test_a_column_of_another_project_is_unknown(): void
    {
        $project = $this->makeProject('column-reorder-mine');
        $elsewhere = $this->makeProject('column-reorder-theirs');
        $this->em->persist(new BoardColumn(project: $elsewhere, label: 'Parked', slug: 'parked', position: 4));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown column "parked"');
        ($this->tool)(['done', 'next', 'in-progress', 'parked']);
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $project = $this->makeProject('column-reorder-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)(['done', 'next', 'in-progress']);
    }
}
