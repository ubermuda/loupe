<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\BoardColumns;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Makes a column the one new cards land in, and takes the flag from the column that had it. */
final readonly class SetDefaultBoardColumnHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumns $rules,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SetDefaultBoardColumnCommand $command): void
    {
        $column = $command->column;

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        $previousId = null;
        $result = $this->em->wrapInTransaction(function () use ($column, &$previousId): bool|string {
            $this->em->lock($column->project, LockMode::PESSIMISTIC_WRITE);
            $columns = $this->boardColumns->findForProjectFresh($column->project);
            if (!\in_array($column, $columns, true)) {
                return 'board.column.error.gone';
            }
            if ($column->isDefault) {
                return false;
            }

            $refusal = $this->rules->refuseDefault($columns, $column);
            if (null !== $refusal) {
                return $refusal;
            }

            foreach ($columns as $other) {
                if ($other->isDefault) {
                    $previousId = (string) $other->id;
                }
                $other->isDefault = $other === $column;
            }
            $this->em->flush();

            return true;
        });

        if (\is_string($result)) {
            throw new DomainErrors(['default' => $result]);
        }
        if (false === $result) {
            return;
        }

        $this->auditor->record(
            'board.column_default_set',
            AuditOutcome::Success,
            ['columnId' => (string) $column->id, 'projectId' => (string) $column->project->id, 'previousColumnId' => $previousId],
            new AuditSubject('board_column', (string) $column->id),
        );
    }
}
