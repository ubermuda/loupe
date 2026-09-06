<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** Who first raised the card. It records the origin and never changes after that. */
enum CardOrigin: string
{
    case Human = 'human';
    case Agent = 'agent';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
