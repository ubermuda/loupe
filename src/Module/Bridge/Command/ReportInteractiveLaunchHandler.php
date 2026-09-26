<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Repository\ProjectRepository;

final readonly class ReportInteractiveLaunchHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private InteractiveRuns $interactiveRuns,
    ) {
    }

    public function __invoke(ReportInteractiveLaunchCommand $command): ReportInteractiveLaunchResult
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return new ReportInteractiveLaunchResult(null, created: false);
        }

        [$run, $created] = match ($command->state) {
            WorkerRunState::Running => $this->interactiveRuns->recordLaunch(
                $project,
                $command->cardId,
                $command->cardNumber,
                $command->sessionId,
                $command->ruleName,
                $command->bridgeId,
            ),
            WorkerRunState::NotStarted => $this->interactiveRuns->recordLaunchFailure(
                $project,
                $command->cardId,
                $command->cardNumber,
                $command->sessionId,
                $command->ruleName,
                $command->bridgeId,
                $command->failureReason ?? throw new \LogicException('A launch that failed carries its reason.'),
                $command->at,
            ),
            default => throw new \LogicException(\sprintf('A launch is running or not-started, never %s.', $command->state->value)),
        };

        return new ReportInteractiveLaunchResult($run, $created);
    }
}
