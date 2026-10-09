<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Workflow\Contract\CardSnapshot;

final readonly class ChildrenFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequests $cardPullRequests,
        private BoardAutomation $boardAutomation,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return ChildrenFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'children';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');
        $children = $this->cards->childProgressOf($stored);
        $epicBranch = $this->boardAutomation->settingsOf($stored->project)->epicBranchOf($stored->number);

        return new ChildrenFacts(
            childCount: $children['total'],
            openChildCount: $children['total'] - $children['done'],
            childMergedIntoEpicBranch: $children['total'] > 0 && null !== $epicBranch && $this->cardPullRequests->childMergedInto($stored, $epicBranch),
        );
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof ChildrenFacts || throw new \LogicException('The provider fingerprints its own facts.');

        // A false merge fact adds nothing, so the stored fingerprints stay as they were.
        return [$facts->childCount, $facts->openChildCount, ...($facts->childMergedIntoEpicBranch ? [true] : [])];
    }
}
