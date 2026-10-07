<?php

declare(strict_types=1);

namespace App\Module\Readiness\Workflow;

use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The latest discovery run of the card waits for a worker. A failed run makes it false, so the engine does not retry. */
final readonly class DiscoveryRequested implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.discovery_requested';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.readiness';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [DiscoveryFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return DiscoveryRunState::Requested === $facts->get(DiscoveryFacts::class)->state;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_discovery_requested' : 'workflow.waiting.card_discovery_requested');
    }
}
