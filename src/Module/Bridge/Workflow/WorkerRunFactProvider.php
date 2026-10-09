<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class WorkerRunFactProvider extends BridgeFactProvider
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WorkerRunFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'worker-runs';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new WorkerRunFacts($this->workerRuns->findOpenWorkKindsOfCard($card->id));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WorkerRunFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::sorted($facts->activeKinds);
    }
}
