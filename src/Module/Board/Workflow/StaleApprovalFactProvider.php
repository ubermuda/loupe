<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\StaleApprovalNoticeQueue;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class StaleApprovalFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private StaleApprovalNoticeQueue $staleApprovalNotices,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return StaleApprovalFacts::class;
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
        $heads = [];
        foreach ($this->staleApprovalNotices->unnoticed($card) as $pullRequest) {
            $heads[($pullRequest->id ?? throw new \LogicException('A stored pull request has an id.'))->toRfc4122()] = $pullRequest->headSha ?? throw new \LogicException('A stale approval has a head.');
        }
        ksort($heads);

        return new StaleApprovalFacts($heads);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof StaleApprovalFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return $facts->heads;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
