<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Event\BoardColumnRenamed;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\BoardColumns;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Gives a column a new label, and the slug that follows from it. The label
 * becomes literal text, so a seeded column stops being translated here.
 */
final readonly class RenameBoardColumnHandler
{
    public const string GONE = 'board.column.error.gone';
    public const string STALE = 'board.column.error.rename_stale';

    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumns $rules,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
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
        $result = $this->em->wrapInTransaction(function () use ($command, $column, $label, $slug): string|RenamedBoardColumn {
            $this->em->lock($column->project, LockMode::PESSIMISTIC_WRITE);
            $columns = $this->boardColumns->findForProjectFresh($column->project);
            if (!\in_array($column, $columns, true)) {
                return self::GONE;
            }
            if ($command->expectedLabel !== $column->label) {
                return self::STALE;
            }

            $refusal = $this->rules->refuseRename($columns, $column, $slug);
            if (null !== $refusal) {
                return $refusal;
            }

            $renamed = new RenamedBoardColumn($column->slug, $slug);
            $column->label = $label;
            $column->slug = $slug;
            $this->em->flush();

            // Inside the transaction, so a listener's rows commit or roll back with the rename.
            $this->events->dispatch(new BoardColumnRenamed($column, $renamed->fromSlug, $renamed->toSlug, $command->actor));

            return $renamed;
        });

        if (\is_string($result)) {
            // A column that went away has no label left to correct.
            throw new DomainErrors([self::GONE === $result ? 'column' : 'label' => $result]);
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
        // Also when the slug stays: the label an open board shows has changed.
        $this->events->dispatch(new BoardColumnsChanged($column->project));

        return $column;
    }
}
