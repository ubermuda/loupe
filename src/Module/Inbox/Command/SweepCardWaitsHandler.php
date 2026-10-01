<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The backstop for a wait or a notice no event reported. While the inbox is
 * off, only a project with an open watch or notice has work, because its items
 * must close.
 */
final readonly class SweepCardWaitsHandler
{
    public function __construct(
        private InboxAvailability $inbox,
        private ProjectRepository $projects,
        private InboxCardWatchRepository $inboxCardWatches,
        private InboxItemRepository $inboxItems,
        private MessageBusInterface $bus,
    ) {
    }

    /** @return int how many reconciles it queued */
    public function __invoke(SweepCardWaitsCommand $command): int
    {
        $projectIds = $this->inbox->isEnabled()
            ? $this->projects->findAllIds()
            : array_values(array_unique([...$this->inboxCardWatches->findProjectIdsWithOpenWatch(), ...$this->inboxItems->findProjectIdsWithOpenNotice()]));

        foreach ($projectIds as $projectId) {
            $this->bus->dispatch(new ReconcileCardWaits($projectId, null));
        }

        return \count($projectIds);
    }
}
