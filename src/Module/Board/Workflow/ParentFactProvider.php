<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\CardSnapshot;

final readonly class ParentFactProvider extends BoardFactProvider
{
    #[\Override]
    public function factsClass(): string
    {
        return ParentFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'parent';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new ParentFacts(null !== $card->parentId);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof ParentFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->isChild;
    }
}
