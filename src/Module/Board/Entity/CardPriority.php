<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/**
 * Integer-backed on purpose. A string backing sorts high, low, medium
 * alphabetically, which puts Low above Medium and breaks the board's ORDER BY.
 */
enum CardPriority: int
{
    case High = 10;
    case Medium = 20;
    case Low = 30;

    /** Agents and humans name a priority, so the name is the input, not the number. */
    public static function fromName(string $name): ?self
    {
        return match (mb_strtolower(trim($name))) {
            'high' => self::High,
            'medium' => self::Medium,
            'low' => self::Low,
            default => null,
        };
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->label(), self::cases());
    }

    /** The name a caller writes and reads, rather than the number the board sorts on. */
    public function label(): string
    {
        return mb_strtolower($this->name);
    }
}
