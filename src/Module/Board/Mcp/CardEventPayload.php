<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The shape card_get_history returns one history row in.
 *
 * A row's detail is stored JSON, so every key is read defensively: a missing
 * or malformed key reads null rather than fail the whole page.
 *
 * @phpstan-type CardEventColumnSummary array{id: string, slug: string, label: string}
 * @phpstan-type CardEventSummary array{kind: string, occurredAt: string, actor: array{kind: string, name: ?string}, from: ?CardEventColumnSummary, to: ?CardEventColumnSummary, cause: ?array<mixed>, run: ?array<string, mixed>, reason: ?string, pullRequest: ?int, pause: ?array{kind: ?string, ruleId: ?string}}
 */
final readonly class CardEventPayload
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<CardEvent> $events
     *
     * @return list<CardEventSummary>
     */
    public function forEvents(array $events): array
    {
        return array_map($this->forEvent(...), $events);
    }

    /** @return CardEventSummary */
    private function forEvent(CardEvent $event): array
    {
        $detail = $event->detail;
        $moved = CardEventKind::Moved === $event->kind;

        return [
            'kind' => $event->kind->value,
            'occurredAt' => $event->occurredAt->format(\DATE_ATOM),
            'actor' => ['kind' => $event->actorKind->value, 'name' => $event->actorUser?->fullName],
            'from' => $moved ? $this->column($detail['from'] ?? null) : null,
            'to' => match ($event->kind) {
                CardEventKind::Moved => $this->column($detail['to'] ?? null),
                CardEventKind::Created => $this->column($detail['column'] ?? null),
                default => null,
            },
            'cause' => \is_array($detail['cause'] ?? null) ? $detail['cause'] : null,
            'run' => CardEventKind::RunFinished === $event->kind ? $detail : null,
            'reason' => \is_string($detail['reason'] ?? null) ? $detail['reason'] : null,
            'pullRequest' => \is_int($detail['pullRequest'] ?? null) ? $detail['pullRequest'] : null,
            'pause' => \in_array($event->kind, [CardEventKind::Paused, CardEventKind::PauseReleased], true) ? [
                'kind' => \is_string($detail['kind'] ?? null) ? $detail['kind'] : null,
                'ruleId' => \is_string($detail['ruleId'] ?? null) ? $detail['ruleId'] : null,
            ] : null,
        ];
    }

    /** @return CardEventColumnSummary|null */
    private function column(mixed $column): ?array
    {
        if (!\is_array($column) || !\is_string($column['id'] ?? null) || !\is_string($column['slug'] ?? null) || !\is_string($column['label'] ?? null)) {
            return null;
        }

        return [
            'id' => $column['id'],
            'slug' => $column['slug'],
            // A seeded label is a translation key, and a renamed one is text that no key matches.
            'label' => $this->translator->trans($column['label']),
        ];
    }
}
