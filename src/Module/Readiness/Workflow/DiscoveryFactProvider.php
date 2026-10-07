<?php

declare(strict_types=1);

namespace App\Module\Readiness\Workflow;

use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Contract\FactProvider;
use Symfony\Component\Uid\Uuid;

final readonly class DiscoveryFactProvider implements FactProvider
{
    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return DiscoveryFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(Uuid $cardId): object
    {
        $run = $this->discoveryRuns->latestForCard($cardId);

        return new DiscoveryFacts(null === $run?->id ? null : (string) $run->id, $run?->state);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof DiscoveryFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return [$facts->runId, $facts->state?->value];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.readiness';
    }
}
