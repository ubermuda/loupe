<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardSnapshot;

final readonly class BlockerFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return BlockerFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'blockers';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $stored = $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.');

        return new BlockerFacts([] !== $this->cards->findOpenBlockersOf($stored));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof BlockerFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->hasOpenBlocker;
    }
}
