<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\FixRunCommentQueue;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class FixRunFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private FixRunCommentQueue $fixRunComments,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return FixRunFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $snapshot): object
    {
        $card = $this->cards->find($snapshot->id) ?? throw new \LogicException('The card of the facts exists.');
        $ids = array_map(static fn (WorkerRun $run): string => ($run->id ?? throw new \LogicException('A stored run has an id.'))->toRfc4122(), $this->fixRunComments->uncommentedRuns($card));
        sort($ids);

        return new FixRunFacts($ids);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof FixRunFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return $facts->uncommentedRunIds;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
