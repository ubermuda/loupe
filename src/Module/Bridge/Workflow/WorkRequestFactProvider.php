<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class WorkRequestFactProvider extends BridgeFactProvider
{
    public function __construct(
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WorkRequestFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'work-requests';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new WorkRequestFacts(array_values(array_unique(array_map(
            static fn ($request): string => $request->kind,
            $this->workRequests->findLiveForCard($card->id),
        ))));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WorkRequestFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::sorted($facts->activeKinds);
    }
}
