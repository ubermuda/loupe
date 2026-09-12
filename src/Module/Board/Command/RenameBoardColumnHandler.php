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

/**
 * Gives a column a new label, and the slug that follows from it. The label
 * becomes literal text, so a seeded column stops being translated here.
 */
final readonly class RenameBoardColumnHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumns $rules,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RenameBoardColumnCommand $command): BoardColumn
    {
        $column = $command->column;
        $label = trim($command->label);
        if (mb_strlen($label) > BoardColumn::MAX_LABEL_LENGTH) {
            throw new DomainErrors(['label' => 'board.column.error.label_too_long']);
        }
        $reserved = $this->rules->refuseLabel($label);
        if (null !== $reserved) {
            throw new DomainErrors(['label' => $reserved]);
        }
        $slug = $this->rules->slugFor($label);

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        $result = $this->em->wrapInTransaction(function () use ($column, $label, $slug): string|RenamedBoardColumn {
            $this->em->lock($column->project, LockMode::PESSIMISTIC_WRITE);
            $columns = $this->boardColumns->findForProjectFresh($column->project);
            if (!\in_array($column, $columns, true)) {
                return 'board.column.error.gone';
            }

            $refusal = $this->rules->refuseRename($columns, $column, $slug);
            if (null !== $refusal) {
                return $refusal;
            }

            $renamed = new RenamedBoardColumn($column->slug, $slug);
            $column->label = $label;
            $column->slug = $slug;
            $this->em->flush();

            return $renamed;
        });

        if (\is_string($result)) {
            throw new DomainErrors(['label' => $result]);
        }

        $this->auditor->record(
            'board.column_renamed',
            AuditOutcome::Success,
            [
                'columnId' => (string) $column->id,
                'projectId' => (string) $column->project->id,
                'fromSlug' => $result->fromSlug,
                'toSlug' => $result->toSlug,
            ],
            new AuditSubject('board_column', (string) $column->id),
        );

        return $column;
    }
}
