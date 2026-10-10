<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class LatestWorkFactProvider implements FactProvider
{
    public function __construct(
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return LatestWorkFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $latest = $this->workRequests->findLatestForCard($card->id);

        return new LatestWorkFacts($latest?->state, $latest?->kind, $latest?->reason);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof LatestWorkFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return [$facts->state?->value, $facts->kind, $facts->reason];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.bridge';
    }
}
