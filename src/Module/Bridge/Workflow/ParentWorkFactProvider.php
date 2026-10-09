<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class ParentWorkFactProvider extends BridgeFactProvider
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return ParentWorkFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'parent-work';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        if (null === $card->parentId) {
            return new ParentWorkFacts([]);
        }

        return new ParentWorkFacts(array_values(array_unique([
            ...$this->workerRuns->findOpenWorkKindsOfCard($card->parentId),
            ...array_map(static fn ($request): string => $request->kind, $this->workRequests->findLiveForCard($card->parentId)),
        ])));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof ParentWorkFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::sorted($facts->activeKinds);
    }
}
