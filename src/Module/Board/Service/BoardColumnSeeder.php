<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives a new project the four columns every board starts with.
 *
 * It persists and never flushes, so the columns commit with the project. Two
 * migrations seed the same four rows for projects created before this existed.
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
        private EntityManagerInterface $em,
    ) {
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
