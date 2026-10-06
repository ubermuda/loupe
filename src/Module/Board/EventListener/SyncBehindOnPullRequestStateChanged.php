<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Messenger\SyncNextPullRequest;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Event\PullRequestStateChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/** A change to one pull request can free the sync line, so the project runs a pass after Forge commits. */
#[AsEventListener]
final readonly class SyncBehindOnPullRequestStateChanged
{
    public function __construct(
        private BoardAutomation $boardAutomation,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        $project = $event->pullRequest->project;
        $settings = $this->boardAutomation->settingsOf($project);
        if (!$settings->enabled || !$settings->syncBehind) {
            return;
        }

        $this->bus->dispatch(new SyncNextPullRequest($project->id ?? throw new \LogicException('A stored pull request has a project id.')));
    }
}
