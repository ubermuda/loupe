<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Mcp\BoardColumnsTool;
use App\Tests\Support\McpRefusalMessages;
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

    public function test_the_seeded_columns_read_in_board_order_with_their_english_labels(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('board-columns-seeded'));

        self::assertSame(['columns' => [
            ['slug' => 'backlog', 'label' => 'Backlog', 'terminal' => false, 'default' => true, 'backlog' => true],
            ['slug' => 'next', 'label' => 'Next', 'terminal' => false, 'default' => false, 'backlog' => false],
            ['slug' => 'in-progress', 'label' => 'In progress', 'terminal' => false, 'default' => false, 'backlog' => false],
            ['slug' => 'done', 'label' => 'Done', 'terminal' => true, 'default' => false, 'backlog' => false],
        ]], ($this->tool)());
    }

    public function test_a_literal_label_reads_as_written_and_position_sets_the_order(): void
    {
        $project = $this->makeProject('board-columns-literal');
        $this->em->persist(new BoardColumn(project: $project, label: 'Won’t do', slug: 'won-t-do', position: -1, terminal: true));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $columns = ($this->tool)()['columns'];

        self::assertSame(['slug' => 'won-t-do', 'label' => 'Won’t do', 'terminal' => true, 'default' => false, 'backlog' => false], $columns[0]);
        self::assertSame(['won-t-do', 'backlog', 'next', 'in-progress', 'done'], array_column($columns, 'slug'));
    }

    public function test_default_and_backlog_mark_the_backlog(): void
    {
        $project = $this->makeProject('board-columns-default');
        $this->actAsMcpTokenBoundTo($project);

        $columns = ($this->tool)()['columns'];

        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_column($columns, 'slug'));
        self::assertSame([true, false, false, false], array_column($columns, 'default'));
        self::assertSame([true, false, false, false], array_column($columns, 'backlog'));
    }

    public function test_another_projects_columns_are_not_listed(): void
    {
        $project = $this->makeProject('board-columns-mine');
        $elsewhere = $this->makeProject('board-columns-theirs');
        $this->em->persist(new BoardColumn(project: $elsewhere, label: 'Parked', slug: 'parked', position: 4));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_column(($this->tool)()['columns'], 'slug'));
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $project = $this->makeProject('board-columns-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)();
    }
}
