<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One session holds at most one open ask. Every call that hands items to a
 * session goes through here, and runs under a write lock on the project, so two
 * calls from one session never open two asks.
 */
final readonly class InboxSessionAsks
{
    public const string SESSION_ASK_ELSEWHERE = 'inbox.ask.error.session_ask_elsewhere';

    /** The unique index that allows one open ask per session. */
    public const string OPEN_SESSION_INDEX = 'uniq_inbox_asks_open_session';

    public function __construct(
        private InboxAskRepository $inboxAsks,
        private EntityManagerInterface $em,
    ) {
    }

    /** Null when the session's open ask belongs to another project, which cannot hold this project's items. */
    public function openOrExtend(Project $project, Uuid $sessionId, ?Uuid $bridgeId, ?string $context): ?SessionAsk
    {
        $ask = $this->inboxAsks->findOpenForSession($sessionId);

        if (null === $ask) {
            $ask = new InboxAsk(project: $project, sessionId: $sessionId, bridgeId: $bridgeId, context: $context);
            $this->em->persist($ask);

            return new SessionAsk($ask, opened: true);
        }

        if ($ask->project !== $project) {
            return null;
        }

        // Appended, so the context of the first call still explains its items.
        if (null !== $context) {
            $ask->context = null === $ask->context ? $context : $ask->context."\n\n".$context;
        }

        return new SessionAsk($ask, opened: false);
    }

    /** Adds the item unless the ask already holds it. */
    public function add(InboxAsk $ask, InboxItem $item): bool
    {
        foreach ($ask->items as $link) {
            if ($link->item === $item) {
                return false;
            }
        }

        $ask->items->add(new InboxAskItem($ask, $item));

        return true;
    }

    /** An ask this call opened with no blocking item has nothing to wait for, so it closes at once. */
    public function closeWhenNothingBlocks(SessionAsk $sessionAsk, \DateTimeImmutable $now): void
    {
        if (!$sessionAsk->opened) {
            return;
        }

        foreach ($sessionAsk->ask->items as $link) {
            if ($link->item->blocking) {
                return;
            }
        }

        $sessionAsk->ask->closedAt = $now;
    }
}
