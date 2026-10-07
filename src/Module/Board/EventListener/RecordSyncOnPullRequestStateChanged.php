<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\RecordPullRequestSyncCommand;
use App\Module\Board\Command\RecordPullRequestSyncHandler;
use App\Module\Forge\Event\PullRequestStateChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The forge accepts an update before its merge commit exists, so only the read
 * that finds the synced head confirms the sync. apply() sets syncedSha in that read.
 * It runs after the project lock.
 */
#[AsEventListener(priority: 5)]
final readonly class RecordSyncOnPullRequestStateChanged
{
    public function __construct(
        private RecordPullRequestSyncHandler $record,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        $head = $event->current->headSha;
        if (null === $head || $event->previous->headSha === $head || $event->pullRequest->syncedSha !== $head) {
            return;
        }

        ($this->record)(new RecordPullRequestSyncCommand($event->pullRequest));
    }
}
