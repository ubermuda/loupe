<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Something a card face shows has changed. It carries ids rather than the
 * card, so it stays valid for a deleted card.
 *
 * A handler that owns its transaction dispatches it after the commit. A path
 * that runs inside another handler's transaction dispatches `updated` alone,
 * because a spurious update costs a page one needless refetch.
 */
final readonly class CardChanged
{
    public const string CREATED = 'created';
    public const string UPDATED = 'updated';
    public const string DELETED = 'deleted';

    /** @param self::CREATED|self::UPDATED|self::DELETED $change */
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
        public string $change,
        public bool $contentChanged,
    ) {
    }
}
