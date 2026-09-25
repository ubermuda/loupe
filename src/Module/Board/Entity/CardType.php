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
    case Epic = 'epic';

    public function tone(): LabelTone
    {
        return match ($this) {
            self::Feature => LabelTone::Lime,
            self::Bug => LabelTone::Amber,
            self::Security => LabelTone::Red,
            self::Tooling => LabelTone::Neutral,
            self::Docs => LabelTone::Green,
            self::Idea => LabelTone::Purple,
            self::Epic => LabelTone::Blue,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
