<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\CardSnapshot;

final readonly class CardTypeFactProvider extends BoardFactProvider
{
    #[\Override]
    public function factsClass(): string
    {
        return CardTypeFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'card-type';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new CardTypeFacts($card->type);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof CardTypeFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->type;
    }
}
