<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\LabelTone;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives a new project its first columns: Backlog, the middle columns, and Done.
 *
 * It persists and never flushes, so the columns commit with the project. Two
 * migrations seed the four default rows for projects created before this existed.
 */
final readonly class BoardColumnSeeder
{
    /** @var list<array{slug: non-empty-string, label: string, tone: LabelTone}> */
    private const array DEFAULT_MIDDLE = [
        ['slug' => 'next', 'label' => 'board.card.status.next', 'tone' => LabelTone::Lime],
        ['slug' => 'in-progress', 'label' => 'board.card.status.in-progress', 'tone' => LabelTone::Purple],
    ];

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /** @return list<BoardColumn> */
    public function seed(Project $project): array
    {
        return $this->seedBetweenBacklogAndDone($project, self::DEFAULT_MIDDLE);
    }

    /**
     * @param list<array{slug: non-empty-string, label: string, tone: LabelTone}> $middle
     *
     * @return list<BoardColumn>
     */
    public function seedBetweenBacklogAndDone(Project $project, array $middle): array
    {
        $columns = [
            ['slug' => BoardColumn::BACKLOG_SLUG, 'label' => 'board.card.status.backlog', 'tone' => LabelTone::Neutral, 'terminal' => false, 'backlog' => true],
            ...array_map(static fn (array $column): array => [...$column, 'terminal' => false, 'backlog' => false], $middle),
            ['slug' => 'done', 'label' => 'board.card.status.done', 'tone' => LabelTone::Green, 'terminal' => true, 'backlog' => false],
        ];

        $seeded = [];
        foreach ($columns as $position => $column) {
            $seeded[] = $boardColumn = new BoardColumn(
                project: $project,
                label: $column['label'],
                slug: $column['slug'],
                position: $position,
                terminal: $column['terminal'],
                backlog: $column['backlog'],
                tone: $column['tone'],
            );
            $this->em->persist($boardColumn);
        }

        return $seeded;
    }
}
