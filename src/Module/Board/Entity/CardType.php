<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** What kind of work a card describes. */
enum CardType: string
{
    case Feature = 'feature';
    case Bug = 'bug';
    case Security = 'security';
    case Tooling = 'tooling';
    case Docs = 'docs';
    case Idea = 'idea';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
