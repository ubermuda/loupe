<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardColumns;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Sets or clears a column's terminal flag, and brings the cards already in it
 * along. A terminal column shows its cards by completion and a column that is
 * not terminal shows them by rank, so a card left without the field its column
 * reads would drop off the board.
 */
final readonly class SetBoardColumnTerminalHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private BoardColumns $rules,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SetBoardColumnTerminalCommand $command): void
    {
        $column = $command->column;

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        $result = $this->em->wrapInTransaction(function () use ($column, $command): bool|string {
            $this->em->lock($column->project, LockMode::PESSIMISTIC_WRITE);
            $columns = $this->boardColumns->findForProjectFresh($column->project);
            if (!\in_array($column, $columns, true)) {
                return 'board.column.error.gone';
            }
            if ($column->terminal === $command->terminal) {
                return false;
            }

            $refusal = $this->rules->refuseTerminal($columns, $column, $command->terminal);
            if (null !== $refusal) {
                return $refusal;
            }

            $column->terminal = $command->terminal;
            if ($command->terminal) {
                $this->em->flush();
                $this->cards->stampCompletion($column, new \DateTimeImmutable());
            } else {
                $this->rankFinishedCards($column);
                $this->em->flush();
            }

            return true;
        });

        if (\is_string($result)) {
            throw new DomainErrors(['terminal' => $result]);
        }
        if (false === $result) {
            return;
        }

        $this->auditor->record(
            'board.column_terminal_set',
            AuditOutcome::Success,
            ['columnId' => (string) $column->id, 'projectId' => (string) $column->project->id, 'terminal' => $command->terminal],
            new AuditSubject('board_column', (string) $column->id),
        );
    }

    /** Clears each card's completion and numbers every priority group from 0, oldest finished first. */
    private function rankFinishedCards(BoardColumn $column): void
    {
        $next = [];
        foreach ($this->cards->findInColumn($column) as $card) {
            $group = $card->priority->value;
            $card->position = $next[$group] ?? 0;
            $next[$group] = $card->position + 1;
            $card->completedAt = null;
        }
    }
}
