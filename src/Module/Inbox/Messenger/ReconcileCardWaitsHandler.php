<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReconcileCardWaitsHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private CardWaitReconciler $reconciler,
    ) {
    }

    public function __invoke(ReconcileCardWaits $message): void
    {
        // A project deleted after the dispatch has nothing left to reconcile.
        $project = $this->projects->find($message->projectId);
        if (null === $project) {
            return;
        }

        $this->reconciler->reconcile($project, $message->cardIds);
    }
}
