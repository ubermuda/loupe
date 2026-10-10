<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** What a reviewer decided about a card in the widget. */
enum CardVerdictKind: string
{
    case Approve = 'approve';
    case RequestChanges = 'request-changes';
    case Comment = 'comment';

    /** Whether the verdict must carry a message of its own: a pending note stands in for it. */
    public function needsMessage(int $pendingNotes): bool
    {
        return self::Approve !== $this && 0 === $pendingNotes;
    }
}
