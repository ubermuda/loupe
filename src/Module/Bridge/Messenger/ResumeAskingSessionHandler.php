<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

use App\Module\Bridge\Command\ResumeAskingRunCommand;
use App\Module\Bridge\Command\ResumeAskingRunHandler;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ResumeAskingSessionHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private ResumeAskingRunHandler $resume,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ResumeAskingSession $message): void
    {
        // Handled inline in the middle of the ask close, the request would take
        // the bridge lock under the project lock. The queue runs it afterwards.
        if ($this->em->getUnitOfWork()->hasPendingInsertions()) {
            $this->bus->dispatch(new Envelope($message, [new TransportNamesStamp(['async'])]));

            return;
        }

        $project = $this->projects->find($message->projectId);
        if (null === $project) {
            return;
        }

        ($this->resume)(new ResumeAskingRunCommand($project, Uuid::fromString($message->bridgeId), Uuid::fromString($message->sessionId)));
    }
}
