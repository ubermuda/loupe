<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Project\Repository\ProjectRepository;

/** Reads what the bridge needs before a resume: whether the ask closed, and whether its session read every item. */
final readonly class CheckInboxAskHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private InboxAskRepository $inboxAsks,
    ) {
    }

    public function __invoke(CheckInboxAskCommand $command): CheckInboxAskView
    {
        // Owner-scoped, so another user's project reads as absent.
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return new CheckInboxAskView(null, null);
        }

        $ask = $this->inboxAsks->findOneByIdAndProject($command->askId, $project);
        if (null === $ask) {
            return new CheckInboxAskView($project, null);
        }

        return new CheckInboxAskView(
            $project,
            $ask,
            // No read is recorded on an open ask, so an open ask is never read, even with no items.
            allRead: null !== $ask->closedAt && array_all($ask->items->toArray(), static fn (InboxAskItem $link): bool => null !== $link->readAt),
        );
    }
}
