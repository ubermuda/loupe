<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

use App\Module\Bridge\Command\RequestSessionUsageCollectionCommand;
use App\Module\Bridge\Command\RequestSessionUsageCollectionHandler;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class CollectSessionUsageHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private RequestSessionUsageCollectionHandler $collect,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(CollectSessionUsage $message): void
    {
        // Handled inline in the middle of a close, the request would take the
        // bridge locks under the project lock. The queue runs it afterwards.
        if ($this->em->getUnitOfWork()->hasPendingInsertions()) {
            $this->bus->dispatch(new Envelope($message, [new TransportNamesStamp(['async'])]));

            return;
        }

        $project = $this->projects->find($message->projectId);
        if (null === $project) {
            return;
        }

        $run = $this->workerRuns->findInteractiveById($project, Uuid::fromString($message->runId));
        if (null === $run) {
            return;
        }

        ($this->collect)(new RequestSessionUsageCollectionCommand($run));
    }
}
