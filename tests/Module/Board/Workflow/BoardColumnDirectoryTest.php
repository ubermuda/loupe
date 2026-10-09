<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Workflow\BoardColumnDirectory;
use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\LabelTone;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardColumnDirectoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_columns_of_a_project_read_in_board_order(): void
    {
        $project = $this->makeProject('columns-list');
        $directory = $this->directory();
        self::assertInstanceOf(BoardColumnDirectory::class, $directory);

        foreach ([$directory->forProject($project->id ?? throw new \LogicException()), $directory->forProjectFresh($project->id)] as $columns) {
            self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_map(static fn ($column): string => $column->slug, $columns));
            self::assertSame([true, false, false, false], array_map(static fn ($column): bool => $column->backlog, $columns));
            self::assertSame([false, false, false, true], array_map(static fn ($column): bool => $column->terminal, $columns));
            self::assertSame((string) $project->id, (string) $columns[0]->projectId);
        }
    }

    public function test_a_column_reads_by_id_with_its_flags_and_tone(): void
    {
        $project = $this->makeProject('columns-find');
        $next = $this->column($project, 'next');

        $view = $this->directory()->find($next->id ?? throw new \LogicException());

        self::assertNotNull($view);
        self::assertSame(['next', 'board.card.status.next', LabelTone::Lime], [$view->slug, $view->label, $view->tone]);
        self::assertSame((string) $next->id, (string) $view->ref()->id);
        self::assertFalse($view->ref()->terminal);
        self::assertNull($this->directory()->find(Uuid::v7()));
    }

    public function test_a_seed_persists_the_backlog_the_middle_columns_and_done(): void
    {
        $project = $this->makeProject('columns-seed');
        $other = new \App\Module\Project\Entity\Project($project->owner, 'seeded-'.uniqid());
        $this->em->persist($other);

        $seeded = $this->directory()->seedBetweenBacklogAndDone($other->id ?? throw new \LogicException(), [
            ['slug' => 'review', 'label' => 'Review', 'tone' => LabelTone::Amber],
        ]);

        self::assertSame(['backlog', 'review', 'done'], array_map(static fn ($column): string => $column->slug, $seeded));
        self::assertSame([0, 1, 2], array_map(static fn ($column): int => $column->position, $seeded));
        $this->em->flush();
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_columns WHERE project_id = ?', [(string) $other->id]));
    }

    private function directory(): BoardColumns
    {
        $directory = self::getContainer()->get(BoardColumns::class);
        self::assertInstanceOf(BoardColumns::class, $directory);

        return $directory;
    }
}
