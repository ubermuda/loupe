<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives a new project the four columns every board starts with.
 *
 * It persists and never flushes, so the columns commit with the project. The
 * migration that created the table seeds the same four rows for older projects.
 */
final readonly class BoardColumnSeeder
{
    /** @var list<array{slug: non-empty-string, terminal: bool, isDefault: bool}> */
    private const array COLUMNS = [
        ['slug' => 'backlog', 'terminal' => false, 'isDefault' => true],
        ['slug' => 'next', 'terminal' => false, 'isDefault' => false],
        ['slug' => 'in-progress', 'terminal' => false, 'isDefault' => false],
        ['slug' => 'done', 'terminal' => true, 'isDefault' => false],
    ];

    public function __construct(
        private BoardColumnRepository $boardColumns,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The project's column with that slug, seeding the four columns first when
     * the project has none. An image that predates the table creates a project
     * without them, and a card written there must still get its column.
     *
     * The caller holds the project lock, so two writes cannot both seed.
     */
    public function columnFor(Project $project, string $slug): ?BoardColumn
    {
        $existing = $this->boardColumns->findForProject($project);
        foreach ([] === $existing ? $this->seed($project) : $existing as $column) {
            if ($column->slug === $slug) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<BoardColumn> */
    public function seed(Project $project): array
    {
        $columns = [];
        foreach (self::COLUMNS as $position => $column) {
            $columns[] = $seeded = new BoardColumn(
                project: $project,
                label: 'board.card.status.'.$column['slug'],
                slug: $column['slug'],
                position: $position,
                terminal: $column['terminal'],
                isDefault: $column['isDefault'],
            );
            $this->em->persist($seeded);
        }

        return $columns;
    }
}
