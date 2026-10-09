<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Contract\CardSnapshot;

final readonly class RefusalFactProvider extends BridgeFactProvider
{
    public function __construct(
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return RefusalFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'refusal';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $settled = $this->workRequests->findLatestSettledForCard($card->id);

        return new RefusalFacts(WorkRequestState::Refused === $settled?->state ? $settled->reason : null);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof RefusalFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->code;
    }
}
