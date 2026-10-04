<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsMessageHandler]
final readonly class ReconcileCardWaitsHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private CardWaitReconciler $reconciler,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ReconcileCardWaits $message): void
    {
        // Handled inline in the middle of another write, the reconcile would
        // flush that write before it is complete. The queue runs it afterwards.
        if ($this->em->getUnitOfWork()->hasPendingInsertions()) {
            $this->bus->dispatch(new Envelope($message, [new TransportNamesStamp(['async'])]));

            return;
        }

        // A project deleted after the dispatch has nothing left to reconcile.
        $project = $this->projects->find($message->projectId);
        if (null === $project) {
            return;
        }

        $this->reconciler->reconcile($project, $message->cardIds);
    }
}
