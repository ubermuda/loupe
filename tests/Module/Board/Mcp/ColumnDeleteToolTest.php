<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\ColumnDeleteTool;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ColumnDeleteToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ColumnDeleteTool $tool;
    private CardCreateTool $createTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ColumnDeleteTool::class);
        self::assertInstanceOf(ColumnDeleteTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_an_empty_column_is_deleted_without_a_target(): void
    {
        $project = $this->makeProject('column-delete-empty');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)('next');

        self::assertSame('next', $result['deleted']);
        self::assertSame(0, $result['movedCards']);
        self::assertNull($result['targetColumn']);
        self::assertSame(['backlog', 'in-progress', 'done'], array_column($result['columns'], 'slug'));

        $this->em->clear();
        $repository = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $repository);
        self::assertNull($repository->findOneBy(['project' => $project->id, 'slug' => 'next']));
    }

    public function test_the_cards_of_the_column_move_to_the_target(): void
    {
        $project = $this->makeProject('column-delete-move');
        $this->actAsMcpTokenBoundTo($project);
        $created = ($this->createTool)('Ship it', 'Body', 'feature', status: 'next');

        $result = ($this->tool)('next', targetColumn: 'done');

        self::assertSame(1, $result['movedCards']);
        self::assertSame('done', $result['targetColumn']);

        $this->em->clear();
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $card = $cards->find($created['cardId']);
        self::assertInstanceOf(Card::class, $card);
        self::assertSame('done', $card->column->slug);
        self::assertNotNull($card->completedAt);
    }

    public function test_a_column_that_holds_cards_needs_a_target(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-delete-no-target'));
        ($this->createTool)('Ship it', 'Body', 'feature', status: 'next');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('targetColumn: This column holds cards. Pass targetColumn, the slug of the column they move to.');
        ($this->tool)('next');
    }

    public function test_the_target_cannot_be_the_deleted_column(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-delete-self'));
        ($this->createTool)('Ship it', 'Body', 'feature', status: 'next');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('targetColumn: targetColumn must name another column of this board.');
        ($this->tool)('next', targetColumn: 'next');
    }

    public function test_the_backlog_cannot_be_deleted(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-delete-backlog'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('slug: Backlog is not a column.');
        ($this->tool)('backlog');
    }

    public function test_the_only_terminal_column_cannot_be_deleted(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-delete-terminal'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('slug: A board needs at least one terminal column.');
        ($this->tool)('done');
    }

    public function test_a_column_of_another_project_is_unknown(): void
    {
        $project = $this->makeProject('column-delete-mine');
        $elsewhere = $this->makeProject('column-delete-theirs');
        $this->em->persist(new BoardColumn(project: $elsewhere, label: 'Parked', slug: 'parked', position: 4));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('slug: Unknown column "parked"');
        ($this->tool)('parked');
    }

    public function test_an_unknown_target_names_the_column_slugs(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('column-delete-unknown-target'));
        ($this->createTool)('Ship it', 'Body', 'feature', status: 'next');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('targetColumn: Unknown column "review". Use one of: backlog, next, in-progress, done.');
        ($this->tool)('next', targetColumn: 'review');
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $project = $this->makeProject('column-delete-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)('next');
    }
}
