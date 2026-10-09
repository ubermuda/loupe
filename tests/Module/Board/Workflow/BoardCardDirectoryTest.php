<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Workflow\BoardCardDirectory;
use App\Module\Workflow\Contract\CardDirectory;
use App\Tests\Module\Board\CardPauseScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardDirectoryTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardPauseScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_port_resolves_to_the_board_implementation(): void
    {
        self::assertInstanceOf(BoardCardDirectory::class, $this->directory());
    }

    public function test_a_card_reads_as_a_snapshot_of_its_column(): void
    {
        $project = $this->makeProject('directory-find');
        $card = $this->cardIn($project);
        $directory = $this->directory();

        $snapshot = $directory->find($card->id ?? throw new \LogicException());

        self::assertNotNull($snapshot);
        self::assertSame((string) $card->id, (string) $snapshot->id);
        self::assertSame((string) $project->id, (string) $snapshot->projectId);
        self::assertSame([$card->number, 'feature'], [$snapshot->number, $snapshot->type]);
        self::assertSame((string) $card->column->id, (string) $snapshot->column->id);
        self::assertTrue($snapshot->column->backlog);
        self::assertFalse($snapshot->column->terminal);
        self::assertNull($snapshot->parentId);
        self::assertNull($directory->find(Uuid::v7()));
    }

    public function test_a_card_of_another_project_is_not_found_in_a_project(): void
    {
        $project = $this->makeProject('directory-project');
        $other = $this->makeProject('directory-other');
        $card = $this->cardIn($project);
        $cardId = $card->id ?? throw new \LogicException();

        self::assertNotNull($this->directory()->findInProject($project->id ?? throw new \LogicException(), $cardId));
        self::assertNull($this->directory()->findInProject($other->id ?? throw new \LogicException(), $cardId));
    }

    public function test_a_refresh_reads_the_column_the_database_holds(): void
    {
        $project = $this->makeProject('directory-refresh');
        $card = $this->cardIn($project);
        $cardId = $card->id ?? throw new \LogicException();
        $done = $this->column($project, 'done');
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET column_id = ? WHERE id = ?', [(string) $done->id, (string) $cardId]);

        $snapshot = $this->directory()->refresh($cardId);

        self::assertNotNull($snapshot);
        self::assertSame((string) $done->id, (string) $snapshot->column->id);
        self::assertTrue($snapshot->column->terminal);
        self::assertNull($this->directory()->refresh(Uuid::v7()));
    }

    public function test_the_child_ids_follow_the_board_order(): void
    {
        $project = $this->makeProject('directory-children');
        $parent = $this->cardIn($project);
        $finished = $this->cardIn($project);
        $finished->parent = $parent;
        $finished->column = $this->column($project, 'done');
        $waiting = $this->cardIn($project);
        $waiting->parent = $parent;
        $this->cardIn($project);
        $this->em->flush();

        $ids = $this->directory()->childIds($parent->id ?? throw new \LogicException());

        self::assertSame([(string) $waiting->id, (string) $finished->id], array_map(strval(...), $ids));
        self::assertSame([], $this->directory()->childIds($waiting->id ?? throw new \LogicException()));
        self::assertSame([], $this->directory()->childIds(Uuid::v7()));
    }

    public function test_a_read_with_the_parent_column_reads_the_stored_column_of_the_parent_and_not_of_the_card(): void
    {
        $project = $this->makeProject('directory-parent-column');
        $parent = $this->cardIn($project);
        $card = $this->cardIn($project);
        $card->parent = $parent;
        $this->em->flush();
        $backlogId = (string) $card->column->id;
        $done = $this->column($project, 'done');
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET column_id = ? WHERE id IN (?, ?)', [(string) $done->id, (string) $card->id, (string) $parent->id]);

        $snapshot = $this->directory()->findWithParentColumn($card->id ?? throw new \LogicException());

        self::assertNotNull($snapshot);
        self::assertSame($backlogId, (string) $snapshot->column->id);
        self::assertSame((string) $done->id, (string) $snapshot->parentColumn?->id);
        self::assertNull($this->directory()->findWithParentColumn($parent->id ?? throw new \LogicException())?->parentColumn);
        self::assertNull($this->directory()->findWithParentColumn(Uuid::v7()));
    }

    private function directory(): CardDirectory
    {
        $directory = self::getContainer()->get(CardDirectory::class);
        self::assertInstanceOf(CardDirectory::class, $directory);

        return $directory;
    }
}
