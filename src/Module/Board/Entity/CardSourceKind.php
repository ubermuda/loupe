<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** Where a card came from. The kind never changes after creation. */
enum CardSourceKind: string
{
    /** A signed-in person filled in a form. */
    case Person = 'person';

    /** Someone using the site-review widget, whom the app cannot name. */
    case Widget = 'widget';

    /** A worker run created the card through the MCP. */
    case Run = 'run';

    /** An agent created the card through the MCP outside any worker run. */
    case Agent = 'agent';

    /** The app itself created the card. */
    case Loupe = 'loupe';
}
