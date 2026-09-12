<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** The kind of party that first raised a card, or that caused an outbox event as its `actor`. */
enum CardOrigin: string
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
