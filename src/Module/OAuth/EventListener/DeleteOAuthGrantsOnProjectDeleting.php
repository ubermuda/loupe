<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventListener;

use App\Module\OAuth\Repository\GrantRepository;
use App\Module\Project\Event\ProjectDeleting;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A grant bound to a deleted project can never be used again, so its rows go with the project. */
#[AsEventListener]
final readonly class DeleteOAuthGrantsOnProjectDeleting
{
    public function __construct(
        private GrantRepository $grants,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->grants->deleteForProject($event->project->id ?? throw new \LogicException('a persisted project always has an id'));
    }
}
