<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\ColumnView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(BoardColumns::class)]
final readonly class BoardColumnDirectory implements BoardColumns
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumnSeeder $seeder,
        private EntityManagerInterface $em,
    ) {
    }

    public static function view(BoardColumn $column): ColumnView
    {
        return new ColumnView(
            $column->id ?? throw new \LogicException('A stored column has an id.'),
            $column->project->id ?? throw new \LogicException('A stored project has an id.'),
            $column->label,
            $column->slug,
            $column->position,
            $column->backlog,
            $column->terminal,
            $column->tone,
        );
    }

    #[\Override]
    public function forProject(Uuid $projectId): array
    {
        return array_map(self::view(...), $this->boardColumns->findForProject($this->project($projectId)));
    }

    #[\Override]
    public function forProjectFresh(Uuid $projectId): array
    {
        return array_map(self::view(...), $this->boardColumns->findForProjectFresh($this->project($projectId)));
    }

    #[\Override]
    public function find(Uuid $columnId): ?ColumnView
    {
        $column = $this->boardColumns->find($columnId);

        return null === $column ? null : self::view($column);
    }

    #[\Override]
    public function seedBetweenBacklogAndDone(Uuid $projectId, array $middle): array
    {
        return array_map(self::view(...), $this->seeder->seedBetweenBacklogAndDone($this->project($projectId), $middle));
    }

    private function project(Uuid $projectId): Project
    {
        return $this->em->getReference(Project::class, $projectId) ?? throw new \LogicException('The project is stored.');
    }
}
