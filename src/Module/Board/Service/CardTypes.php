<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\LabelTone;

/** The card types of one project. */
final readonly class CardTypes
{
    /** @param list<CardTypeDefinition> $all */
    public function __construct(
        public array $all,
        public string $defaultKey,
    ) {
    }

    /** A card can keep a type its template no longer declares. Such a type is neutral and has no capability. */
    public function get(string $key): CardTypeDefinition
    {
        foreach ($this->all as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return new CardTypeDefinition($key, $key, LabelTone::Neutral, false, false);
    }

    public function has(string $key): bool
    {
        return array_any($this->all, static fn (CardTypeDefinition $type): bool => $type->key === $key);
    }

    public function default(): CardTypeDefinition
    {
        return $this->get($this->defaultKey);
    }
}
