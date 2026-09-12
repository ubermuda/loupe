<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/**
 * Who first raised the card. A card's reporter never changes after creation.
 * The same values name the `actor` that caused an outbox event.
 */
enum CardReporter: string
{
    case Human = 'human';
    case Agent = 'agent';

    /**
     * Someone using the site-review widget, who is not signed in and whom the
     * app cannot name. Distinct from Human, which claims a person the app
     * authenticated, and from Agent, which claims a tool.
     */
    case Reviewer = 'reviewer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
