<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\WritePullRequestFactEventsCommand;
use App\Module\Board\Command\WritePullRequestFactEventsHandler;
use App\Module\Forge\Event\PullRequestStateChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A throw here rolls back the state Forge stored, so the next read writes again. */
#[AsEventListener]
final readonly class WritePullRequestFactEventsOnStateChanged
{
    public function __construct(
        private WritePullRequestFactEventsHandler $write,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        ($this->write)(new WritePullRequestFactEventsCommand($event->pullRequest, $event->previous, $event->current, $event->reviewVerdict));
    }
}
