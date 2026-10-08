<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\CardSource;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** The worker run that created a card through the MCP, read from the session header that the `loupe` CLI sends. */
final readonly class AgentRunSource
{
    public function __construct(
        private RequestStack $requests,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /** Null when the call names no session, or a session with no worker run in the project. */
    public function forProject(Project $project): ?CardSource
    {
        $session = $this->requests->getCurrentRequest()?->headers->get(AgentRunCause::SESSION_HEADER);
        if (null === $session || !Uuid::isValid($session)) {
            return null;
        }

        $run = $this->workerRuns->findWorkerOfSession($project, Uuid::fromString($session));
        if (null === $run) {
            return null;
        }

        return CardSource::run($run->id ?? throw new \LogicException('A persisted run has an id.'), $run->cardId());
    }
}
