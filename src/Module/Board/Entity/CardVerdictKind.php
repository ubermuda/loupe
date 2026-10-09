<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** What a reviewer decided about a card in the widget. */
enum CardVerdictKind: string
{
    case Approve = 'approve';
    case RequestChanges = 'request-changes';
    case Comment = 'comment';

    /** Whether the verdict must carry a message of its own. */
    public function needsMessage(): bool
    {
        return self::Approve !== $this;
    }
}
