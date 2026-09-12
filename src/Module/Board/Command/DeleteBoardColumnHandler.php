<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardColumns;
use App\Module\Board\Service\CardMover;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Deletes a column. A column that holds cards moves them to the target first,
 * each through CardMover, so a bulk move follows the same completion rules as
 * a drag.
 *
 * The moves dispatch no CardMoved: the outbox must not see one event per card
 * for a single delete, so only the audit trail records each move.
 */
final readonly class DeleteBoardColumnHandler
{
    public const string TARGET_REQUIRED = 'board.column.error.target_required';
    public const string TARGET_INVALID = 'board.column.error.target_invalid';

    public function __construct(
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private CardMover $mover,
        private BoardColumns $rules,
        private EntityManagerInterface $em,
        private Auditor $auditor,
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

            $cards = $this->cards->findInColumn($column);
            $target = $command->target;
            if ([] !== $cards && null === $target) {
                return ['target' => self::TARGET_REQUIRED];
            }
            if (null !== $target && ($target === $column || !\in_array($target, $columns, true))) {
                return ['target' => self::TARGET_INVALID];
            }

            $movedCardIds = [];
            foreach ($cards as $card) {
                $move = $this->mover->move($card, $target ?? throw new \LogicException('A column with cards has a target.'), $card->priority);
                // Each append reads the end of the target group from the
                // database, so the card before it must already be there.
                $this->em->flush();
                $moves[] = $move->auditContext($card);
                $movedCardIds[] = (string) $card->id;
            }

            // Read before the remove, which clears the id.
            $deleted = new DeletedBoardColumn(
                columnId: (string) $column->id,
                projectId: (string) $column->project->id,
                slug: $column->slug,
                targetSlug: [] === $cards ? null : $target?->slug,
                movedCardIds: $movedCardIds,
            );

            $this->em->remove($column);
            $position = 0;
            foreach ($columns as $remaining) {
                if ($remaining !== $column) {
                    $remaining->position = $position++;
                }
            }
            $this->em->flush();

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
                new AuditSubject('card', (string) $context['cardId']),
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

        return $result;
    }
}
