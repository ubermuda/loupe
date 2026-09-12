<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\BoardColumns;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Appends a column after the last one. A new column is neither terminal nor the default. */
final readonly class AddBoardColumnHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumns $rules,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AddBoardColumnCommand $command): BoardColumn
    {
        $label = trim($command->label);
        if (mb_strlen($label) > BoardColumn::MAX_LABEL_LENGTH) {
            throw new DomainErrors(['label' => 'board.column.error.label_too_long']);
        }
        $slug = $this->rules->slugFor($label);

        // The refusal leaves the closure as a value: a throw inside it closes
        // the EntityManager, and the refused form still renders the board.
        $result = $this->em->wrapInTransaction(function () use ($command, $label, $slug): BoardColumn|string {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);
            $columns = $this->boardColumns->findForProjectFresh($command->project);

            $refusal = $this->rules->refuseAdd($columns, $slug);
            if (null !== $refusal) {
                return $refusal;
            }

            $position = [] === $columns ? 0 : max(array_map(static fn (BoardColumn $column): int => $column->position, $columns)) + 1;
            $column = new BoardColumn(project: $command->project, label: $label, slug: $slug, position: $position);
            $this->em->persist($column);
            $this->em->flush();

            return $column;
        });

        if (\is_string($result)) {
            throw new DomainErrors(['label' => $result]);
        }

        $this->auditor->record(
            'board.column_added',
            AuditOutcome::Success,
            ['columnId' => (string) $result->id, 'projectId' => (string) $command->project->id, 'slug' => $result->slug],
            new AuditSubject('board_column', (string) $result->id),
        );

        return $result;
    }
}
