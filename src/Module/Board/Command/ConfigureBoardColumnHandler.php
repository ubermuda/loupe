<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Event\BoardColumnRenamed;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\BoardColumns;
use App\Module\Board\Service\TerminalColumnCards;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Saves a column's name, default flag and terminal flag together, in one
 * transaction, and refuses the whole save when the result breaks a board rule.
 */
final readonly class ConfigureBoardColumnHandler
{
    public const string TERMINAL_STALE = 'board.column.error.terminal_stale';

    /** The form field each board rule reports on. */
    private const array FIELDS = [
        BoardColumns::NO_TERMINAL => 'terminal',
        BoardColumns::DEFAULT_TERMINAL => 'terminal',
        BoardColumns::NO_SINGLE_DEFAULT => 'isDefault',
    ];

    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumns $rules,
        private TerminalColumnCards $terminalCards,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ConfigureBoardColumnCommand $command): void
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

        $renamed = null;
        $previousDefaultId = null;
        $terminalChanged = false;
        $toneChanged = false;
        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        $errors = $this->em->wrapInTransaction(function () use ($command, $column, $label, &$renamed, &$previousDefaultId, &$terminalChanged, &$toneChanged): array {
            $this->em->lock($column->project, LockMode::PESSIMISTIC_WRITE);
            $columns = $this->boardColumns->findForProjectFresh($column->project);
            if (!\in_array($column, $columns, true)) {
                return ['column' => RenameBoardColumnHandler::GONE];
            }
            $default = array_find($columns, static fn (BoardColumn $other): bool => $other->isDefault);
            if ($command->expectedLabel !== $column->label) {
                return ['label' => RenameBoardColumnHandler::STALE];
            }
            if ($command->expectedDefaultId !== (string) $default?->id) {
                return ['isDefault' => SetDefaultBoardColumnHandler::STALE];
            }
            if ($command->expectedTerminal !== $column->terminal) {
                return ['terminal' => self::TERMINAL_STALE];
            }

            // The dialog shows a seeded label translated, so an unchanged name keeps the key.
            $keepLabel = $label === $this->translator->trans($column->label);
            $slug = $keepLabel ? $column->slug : $this->rules->slugFor($label);
            $refusal = $this->rules->refuseConfigure($columns, $column, $slug, $command->terminal, $command->isDefault);
            if (null !== $refusal) {
                return [self::FIELDS[$refusal] ?? 'label' => $refusal];
            }

            if (!$keepLabel) {
                $renamed = new RenamedBoardColumn($column->slug, $slug);
                $column->label = $label;
                $column->slug = $slug;
            }
            if ($command->isDefault && !$column->isDefault) {
                $previousDefaultId = (string) $default?->id;
                foreach ($columns as $other) {
                    $other->isDefault = $other === $column;
                }
            }
            $terminalChanged = $command->terminal !== $column->terminal;
            $column->terminal = $command->terminal;
            $toneChanged = null !== $command->tone && $command->tone !== $column->tone;
            if ($toneChanged) {
                $column->tone = $command->tone;
            }
            $this->em->flush();

            if (null !== $renamed) {
                // Inside the transaction, so a listener's rows commit or roll back with the rename.
                $this->events->dispatch(new BoardColumnRenamed($column, $renamed->fromSlug, $renamed->toSlug, $command->actor));
            }
            if ($terminalChanged) {
                $this->terminalCards->follow($column, new \DateTimeImmutable());
            }

            return [];
        });

        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        $subject = new AuditSubject('board_column', (string) $column->id);
        $context = ['columnId' => (string) $column->id, 'projectId' => (string) $column->project->id];
        if (null !== $renamed) {
            $this->auditor->record('board.column_renamed', AuditOutcome::Success, $context + ['fromSlug' => $renamed->fromSlug, 'toSlug' => $renamed->toSlug], $subject);
        }
        if (null !== $previousDefaultId) {
            $this->auditor->record('board.column_default_set', AuditOutcome::Success, $context + ['previousColumnId' => $previousDefaultId], $subject);
        }
        if ($terminalChanged) {
            $this->auditor->record('board.column_terminal_set', AuditOutcome::Success, $context + ['terminal' => $column->terminal], $subject);
        }
        if ($toneChanged) {
            $this->auditor->record('board.column_tone_set', AuditOutcome::Success, $context + ['tone' => $column->tone->value], $subject);
        }
        if (null !== $renamed || null !== $previousDefaultId || $terminalChanged || $toneChanged) {
            $this->events->dispatch(new BoardColumnsChanged($column->project));
        }
    }
}
