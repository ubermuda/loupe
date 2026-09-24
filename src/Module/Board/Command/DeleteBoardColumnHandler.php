<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardColumns;
use App\Module\Bridge\Service\InteractiveRuns;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Deletes a column. A column that holds cards moves them to the target first,
 * in bulk, with the completion rules a drag follows, and closes their open
 * interactive runs as any move to another column does.
 *
 * The bulk move reads and writes database rows under the project lock, so it
 * needs no CardRepository::refreshColumn(). A moved card loaded before the call
 * is re-read afterwards.
 *
 * The move dispatches no CardMoved: the outbox must not see one event per card
 * for a single delete, so only the audit trail records each move. The delete
 * dispatches one BoardColumnDeleted, which names every moved card.
 */
final readonly class DeleteBoardColumnHandler
{
    public const string TARGET_REQUIRED = 'board.column.error.target_required';
    public const string TARGET_INVALID = 'board.column.error.target_invalid';

    public function __construct(
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private BoardColumns $rules,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private InteractiveRuns $interactiveRuns,
    ) {
    }

    public function __invoke(DeleteBoardColumnCommand $command): DeletedBoardColumn
    {
        $column = $command->column;
        $moves = [];

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        $result = $this->em->wrapInTransaction(function () use ($command, $column, &$moves): DeletedBoardColumn|array {
            $this->em->lock($column->project, LockMode::PESSIMISTIC_WRITE);
            $columns = $this->boardColumns->findForProjectFresh($column->project);
            if (!\in_array($column, $columns, true)) {
                return ['column' => 'board.column.error.gone'];
            }

            $refusal = $this->rules->refuseDelete($columns, $column);
            if (null !== $refusal) {
                return ['column' => $refusal];
            }

            $rows = $this->cards->findRowsInColumn($column);
            $target = $command->target;
            if ([] !== $rows && null === $target) {
                return ['target' => self::TARGET_REQUIRED];
            }
            if (null !== $target && ($target === $column || !\in_array($target, $columns, true))) {
                return ['target' => self::TARGET_INVALID];
            }

            // Read before the remove, which clears the id.
            $deleted = new DeletedBoardColumn(
                columnId: (string) $column->id,
                projectId: (string) $column->project->id,
                slug: $column->slug,
                targetSlug: [] === $rows ? null : $target?->slug,
                movedCardIds: array_column($rows, 'id'),
            );

            if (null !== $target && [] !== $rows) {
                $now = new \DateTimeImmutable();
                $this->cards->moveAll($column, $target, $now);
                $this->interactiveRuns->closeOnMove($column->project, array_map(Uuid::fromString(...), $deleted->movedCardIds));
                if (!$target->terminal) {
                    $this->cards->renumberColumn($target, $now);
                }
                $this->cards->refreshLoadedFrom($column);

                $positions = $this->cards->positionsInColumn($target);
                foreach ($rows as $row) {
                    // The shape CardMove::auditContext() gives a single move.
                    $moves[] = [
                        'cardId' => $row['id'],
                        'cardNumber' => $row['number'],
                        'projectId' => $deleted->projectId,
                        'fromStatus' => $deleted->slug,
                        'fromColumnId' => $deleted->columnId,
                        'toStatus' => $target->slug,
                        'toColumnId' => (string) $target->id,
                        'position' => $positions[$row['id']] ?? 0,
                    ];
                }
            }

            $this->em->remove($column);
            $position = 0;
            foreach ($columns as $remaining) {
                if ($remaining !== $column) {
                    $remaining->position = $position++;
                }
            }
            $this->em->flush();

            // Inside the transaction, so a listener's rows commit or roll back with the delete.
            $this->events->dispatch(new BoardColumnDeleted(
                project: $column->project,
                columnId: $deleted->columnId,
                slug: $deleted->slug,
                targetSlug: $deleted->targetSlug,
                movedCardIds: $deleted->movedCardIds,
                actor: $command->actor,
            ));

            return $deleted;
        });

        if (\is_array($result)) {
            throw new DomainErrors($result);
        }

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback.
        foreach ($moves as $context) {
            $this->auditor->record(
                'board.card_moved',
                AuditOutcome::Success,
                $context,
                new AuditSubject('card', $context['cardId']),
            );
        }

        $this->auditor->record(
            'board.column_deleted',
            AuditOutcome::Success,
            [
                'columnId' => $result->columnId,
                'projectId' => $result->projectId,
                'slug' => $result->slug,
                'targetSlug' => $result->targetSlug,
                'movedCards' => \count($result->movedCardIds),
            ],
            new AuditSubject('board_column', $result->columnId),
        );
        $this->events->dispatch(new BoardColumnsChanged($column->project));

        return $result;
    }
}
