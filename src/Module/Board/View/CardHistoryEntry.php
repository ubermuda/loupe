<?php

declare(strict_types=1);

namespace App\Module\Board\View;

use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\WorkerRunListItem;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;

/**
 * One row of the History tab, read from a card event.
 *
 * The detail is JSON that older writers shaped differently, so a field that
 * does not read gives the plain `changed` sentence rather than an error.
 */
final readonly class CardHistoryEntry
{
    private const array REASONS = [
        'conflict' => 'board.card.automation.reason.conflict',
        'checks-failed' => 'board.card.automation.reason.checks_failed',
        'changes-requested' => 'board.card.automation.reason.changes_requested',
    ];

    private function __construct(
        public string $icon,
        public \DateTimeImmutable $occurredAt,
        public string|TranslatableMessage $actor,
        public TranslatableMessage $sentence,
        public ?TranslatableMessage $cause = null,
        public ?CardHistoryRun $run = null,
    ) {
    }

    /** @param bool $runExists whether the run of a `run-finished` row is still stored, so the row can link to it */
    public static function of(CardEvent $event, bool $runExists = false): self
    {
        $actor = self::actor($event);
        $detail = $event->detail;

        $entry = match ($event->kind) {
            CardEventKind::Created => self::created($event, $actor, $detail),
            CardEventKind::Moved => self::moved($event, $actor, $detail),
            CardEventKind::FixRequested => self::automation($event, $actor, $detail, 'lucide:wrench', 'board.card.history.fix_requested'),
            CardEventKind::Stopped => self::automation($event, $actor, $detail, 'lucide:ban', 'board.card.history.stopped'),
            CardEventKind::ReadyToMerge => \is_int($detail['pullRequest'] ?? null)
                ? new self('lucide:git-merge', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.ready_to_merge', ['%pr%' => $detail['pullRequest']]))
                : null,
            CardEventKind::RunFinished => self::runFinished($event, $actor, $detail, $runExists),
        };

        return $entry ?? new self('lucide:history', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.changed', ['%actor%' => $actor]));
    }

    /** The run id of a `run-finished` row, when it is a UUID a query can bind. */
    public static function runIdOf(CardEvent $event): ?string
    {
        $runId = $event->detail['runId'] ?? null;

        return CardEventKind::RunFinished === $event->kind && \is_string($runId) && Uuid::isValid($runId) ? $runId : null;
    }

    private static function actor(CardEvent $event): string|TranslatableMessage
    {
        $user = $event->actorUser;

        return match ($event->actorKind) {
            CardReporter::System => new TranslatableMessage('board.card.history.actor.system'),
            CardReporter::Reviewer => new TranslatableMessage('board.card.history.actor.reviewer'),
            CardReporter::Human => $user->fullName ?? new TranslatableMessage('board.card.history.actor.deleted'),
            CardReporter::Agent => null === $user
                ? new TranslatableMessage('board.card.history.actor.deleted')
                : new TranslatableMessage('board.card.history.actor.agent', ['%name%' => $user->fullName]),
        };
    }

    /** @param array<string, mixed> $detail */
    private static function created(CardEvent $event, string|TranslatableMessage $actor, array $detail): ?self
    {
        $column = self::column($detail['column'] ?? null);

        return null === $column ? null : new self('lucide:plus', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.created', [
            '%actor%' => $actor,
            '%column%' => $column,
        ]));
    }

    /** @param array<string, mixed> $detail */
    private static function moved(CardEvent $event, string|TranslatableMessage $actor, array $detail): ?self
    {
        $from = self::column($detail['from'] ?? null);
        $to = self::column($detail['to'] ?? null);
        if (null === $from || null === $to) {
            return null;
        }

        return new self('lucide:arrow-right', $event->occurredAt, $actor, new TranslatableMessage('board.card.history.moved', [
            '%actor%' => $actor,
            '%from%' => $from,
            '%to%' => $to,
        ]), self::cause($detail['cause'] ?? null));
    }

    /** @param array<string, mixed> $detail */
    private static function automation(CardEvent $event, string|TranslatableMessage $actor, array $detail, string $icon, string $key): ?self
    {
        $pullRequest = $detail['pullRequest'] ?? null;
        if (!\is_int($pullRequest)) {
            return null;
        }
        $reason = $detail['reason'] ?? null;
        $reasonKey = \is_string($reason) ? self::REASONS[$reason] ?? null : null;

        return new self(
            $icon,
            $event->occurredAt,
            $actor,
            new TranslatableMessage($key, ['%actor%' => $actor, '%pr%' => $pullRequest]),
            null === $reasonKey ? null : new TranslatableMessage('board.card.history.reason', ['%reason%' => new TranslatableMessage($reasonKey)]),
        );
    }

    /** @param array<string, mixed> $detail */
    private static function runFinished(CardEvent $event, string|TranslatableMessage $actor, array $detail, bool $runExists): ?self
    {
        $rule = $detail['ruleName'] ?? null;
        if (!\is_string($rule)) {
            return null;
        }
        $rawState = $detail['state'] ?? null;
        $state = \is_string($rawState) ? WorkerRunState::tryFrom($rawState) : null;
        $seconds = $detail['durationSeconds'] ?? null;

        return new self(
            'lucide:bot',
            $event->occurredAt,
            $actor,
            new TranslatableMessage(
                \in_array($state, [WorkerRunState::NotStarted, WorkerRunState::Skipped, WorkerRunState::Replaced, WorkerRunState::Dropped], true)
                    ? 'board.card.history.run_not_started'
                    : 'board.card.history.run_finished',
                ['%actor%' => $actor, '%rule%' => $rule],
            ),
            run: new CardHistoryRun(
                runId: $runExists ? self::runIdOf($event) : null,
                duration: \is_int($seconds) && $seconds >= 0 ? WorkerRunListItem::formatDuration($seconds) : null,
                stateKey: $state?->translationKey() ?? 'board.card.history.run_state_unknown',
                chipModifier: $state?->chipModifier() ?? 'resolved',
                interactive: true === ($detail['interactive'] ?? null),
            ),
        );
    }

    /** A seeded column stores its label as a translation key, and a label a person wrote translates to itself. */
    private static function column(mixed $column): ?TranslatableMessage
    {
        $label = \is_array($column) ? $column['label'] ?? null : null;

        return \is_string($label) ? new TranslatableMessage($label) : null;
    }

    private static function cause(mixed $cause): ?TranslatableMessage
    {
        if (!\is_array($cause)) {
            return null;
        }
        // A removed link or a column change names no single blocker.
        if ('unblocked' === ($cause['type'] ?? null) && !\array_key_exists('blocker', $cause)) {
            return new TranslatableMessage('board.card.history.cause.unblocked_any');
        }
        [$key, $field, $parameter] = match ($cause['type'] ?? null) {
            'merged' => ['board.card.history.cause.merged', 'pullRequest', '%pr%'],
            'checks-passed' => ['board.card.history.cause.checks_passed', 'pullRequest', '%pr%'],
            'document-approved' => ['board.card.history.cause.document_approved', 'document', '%document%'],
            'epic-reconciled' => ['board.card.history.cause.epic_reconciled', 'child', '%child%'],
            'unblocked' => ['board.card.history.cause.unblocked', 'blocker', '%blocker%'],
            'column-deleted' => ['board.card.history.cause.column_deleted', 'column', '%column%'],
            'abandoned' => ['board.card.history.cause.abandoned', null, null],
            'run' => ['board.card.history.cause.run', 'rule', '%rule%'],
            default => [null, null, null],
        };
        if (null === $key) {
            return null;
        }
        if (null === $field || null === $parameter) {
            return new TranslatableMessage($key);
        }
        $value = $cause[$field] ?? null;
        if (!\is_int($value) && !\is_string($value)) {
            return null;
        }

        return new TranslatableMessage($key, [$parameter => 'column' === $field && \is_string($value) ? new TranslatableMessage($value) : $value]);
    }
}
