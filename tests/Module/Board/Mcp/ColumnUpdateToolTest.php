<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Mcp\ColumnUpdateTool;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ColumnUpdateToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ColumnUpdateTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ColumnUpdateTool::class);
        self::assertInstanceOf(ColumnUpdateTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_a_rename_changes_the_label_and_the_slug(): void
    {
        $project = $this->makeProject('column-update-rename');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)('next', label: 'Ready');

        self::assertSame('ready', $result['column']);
        self::assertSame(['backlog', 'ready', 'in-progress', 'done'], array_column($result['columns'], 'slug'));
        self::assertSame('Ready', $this->stored($project->id, 'ready')->label);
    }

    public function test_an_omitted_label_keeps_a_seeded_column_slug_and_label(): void
    {
        $project = $this->makeProject('column-update-keep');
        $seededLabel = $this->column($project, 'next')->label;
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)('next', terminal: true);

        self::assertSame('next', $result['column']);
        $stored = $this->stored($project->id, 'next');
        self::assertTrue($stored->terminal);
        self::assertSame($seededLabel, $stored->label);
    }

    public function test_an_omitted_terminal_keeps_the_flag(): void
    {
        $project = $this->makeProject('column-update-terminal-kept');
        $this->actAsMcpTokenBoundTo($project);

        ($this->tool)('done', label: 'Shipped');

        self::assertTrue($this->stored($project->id, 'shipped')->terminal);
    }

    public function test_a_rename_to_a_taken_slug_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-update-taken'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('label: Another column on this board already has the slug');
        ($this->tool)('next', label: 'Done');
    }

    public function test_a_label_that_is_a_translation_key_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-update-reserved'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('label: The app uses this text internally. Choose another label.');
        ($this->tool)('next', label: 'board.column.error.gone');
    }

    public function test_a_label_with_no_letter_or_digit_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-update-slug-empty'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('label: A column label needs at least one letter or digit, because the slug comes from the label.');
        ($this->tool)('next', label: '!!!');
    }

    public function test_an_over_long_label_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-update-long'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(\sprintf('label: A column label must be at most %d characters.', BoardColumn::MAX_LABEL_LENGTH));
        ($this->tool)('next', label: str_repeat('a', BoardColumn::MAX_LABEL_LENGTH + 1));
    }

    public function test_the_only_terminal_column_cannot_stop_being_terminal(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-update-no-terminal'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('terminal: A board needs at least one terminal column. Mark another column terminal with column_update first.');
        ($this->tool)('done', terminal: false);
    }

    public function test_the_backlog_cannot_be_changed(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-update-backlog'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('slug: Backlog is not a column.');
        ($this->tool)('backlog', terminal: true);
    }

    public function test_a_column_of_another_project_is_unknown(): void
    {
        $project = $this->makeProject('column-update-mine');
        $elsewhere = $this->makeProject('column-update-theirs');
        $this->em->persist(new BoardColumn(project: $elsewhere, label: 'Parked', slug: 'parked', position: 4));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('slug: Unknown column "parked"');
        ($this->tool)('parked', label: 'Mine now');
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $project = $this->makeProject('column-update-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)('next', label: 'Ready');
    }

    private function stored(?Uuid $projectId, string $slug): BoardColumn
    {
        $this->em->clear();
        $repository = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $repository);
        $stored = $repository->findOneBy(['project' => $projectId, 'slug' => $slug]);
        self::assertInstanceOf(BoardColumn::class, $stored);

        return $stored;
    }
}
