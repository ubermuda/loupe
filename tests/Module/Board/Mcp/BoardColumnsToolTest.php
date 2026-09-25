<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Mcp\BoardColumnsTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoardColumnsToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private BoardColumnsTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(BoardColumnsTool::class);
        self::assertInstanceOf(BoardColumnsTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->disableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('board-columns-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)();
    }

    public function test_the_seeded_columns_read_in_board_order_with_their_english_labels(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('board-columns-seeded'));

        self::assertSame(['columns' => [
            ['slug' => 'backlog', 'label' => 'Backlog', 'terminal' => false, 'default' => true],
            ['slug' => 'next', 'label' => 'Next', 'terminal' => false, 'default' => false],
            ['slug' => 'in-progress', 'label' => 'In progress', 'terminal' => false, 'default' => false],
            ['slug' => 'done', 'label' => 'Done', 'terminal' => true, 'default' => false],
        ]], ($this->tool)());
    }

    public function test_a_literal_label_reads_as_written_and_position_sets_the_order(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('board-columns-literal');
        $this->em->persist(new BoardColumn(project: $project, label: 'Won’t do', slug: 'won-t-do', position: -1, terminal: true));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $columns = ($this->tool)()['columns'];

        self::assertSame(['slug' => 'won-t-do', 'label' => 'Won’t do', 'terminal' => true, 'default' => false], $columns[0]);
        self::assertSame(['won-t-do', 'backlog', 'next', 'in-progress', 'done'], array_column($columns, 'slug'));
    }

    public function test_default_marks_the_default_column_wherever_it_sits(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('board-columns-default');
        $this->column($project, 'backlog')->isDefault = false;
        $this->em->persist(new BoardColumn(project: $project, label: 'Inbox', slug: 'inbox', position: 4, isDefault: true));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $columns = ($this->tool)()['columns'];

        self::assertSame(['backlog', 'next', 'in-progress', 'done', 'inbox'], array_column($columns, 'slug'));
        self::assertSame([false, false, false, false, true], array_column($columns, 'default'));
    }

    public function test_another_projects_columns_are_not_listed(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('board-columns-mine');
        $elsewhere = $this->makeProject('board-columns-theirs');
        $this->em->persist(new BoardColumn(project: $elsewhere, label: 'Parked', slug: 'parked', position: 4));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_column(($this->tool)()['columns'], 'slug'));
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('board-columns-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)();
    }
}
