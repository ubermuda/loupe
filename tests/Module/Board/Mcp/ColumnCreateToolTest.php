<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Mcp\ColumnCreateTool;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ColumnCreateToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ColumnCreateTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ColumnCreateTool::class);
        self::assertInstanceOf(ColumnCreateTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_a_new_column_goes_after_the_last_and_is_not_terminal(): void
    {
        $project = $this->makeProject('column-create');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)('Code review');

        self::assertSame('code-review', $result['column']);
        self::assertSame(['backlog', 'next', 'in-progress', 'done', 'code-review'], array_column($result['columns'], 'slug'));
        self::assertSame(['slug' => 'code-review', 'label' => 'Code review', 'terminal' => false, 'default' => false, 'backlog' => false], $result['columns'][4]);

        $this->em->clear();
        $repository = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $repository);
        $stored = $repository->findOneBy(['project' => $project->id, 'slug' => 'code-review']);
        self::assertInstanceOf(BoardColumn::class, $stored);
        self::assertFalse($stored->terminal);
    }

    public function test_a_label_whose_slug_is_taken_is_refused_with_the_agent_sentence(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-create-taken'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('label: Another column on this board already has the slug');
        ($this->tool)('Next');
    }

    public function test_an_over_long_label_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-create-long'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(\sprintf('at most %d characters', BoardColumn::MAX_LABEL_LENGTH));
        ($this->tool)(str_repeat('a', BoardColumn::MAX_LABEL_LENGTH + 1));
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $project = $this->makeProject('column-create-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)('Code review');
    }
}
