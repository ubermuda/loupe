<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

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

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (CardTypeDefinition $type): string => $type->key, $this->all);
    }

    /** @return list<string> the keys of the types that may have children */
    public function withChildren(): array
    {
        return array_values(array_map(
            static fn (CardTypeDefinition $type): string => $type->key,
            array_filter($this->all, static fn (CardTypeDefinition $type): bool => $type->children),
        ));
    }

    /** @return list<string> the keys of the types that get a lane */
    public function withLane(): array
    {
        return array_values(array_map(
            static fn (CardTypeDefinition $type): string => $type->key,
            array_filter($this->all, static fn (CardTypeDefinition $type): bool => $type->lane),
        ));
    }

    public function default(): CardTypeDefinition
    {
        return $this->get($this->defaultKey);
    }
}
