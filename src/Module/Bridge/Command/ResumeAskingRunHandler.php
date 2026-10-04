<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use Psr\Log\LoggerInterface;

/**
 * Asks the bridge of a session to resume its newest run, once an ask of the
 * session closed. Loupe requests the resume, so it names no person. A run that
 * the request refuses, such as one that still runs, gets no resume. A run that
 * got a resume since the close gets no second one.
 */
final readonly class ResumeAskingRunHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private BridgeCommandRepository $bridgeCommands,
        private RequestBridgeCommandHandler $requestCommand,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeAskingRunCommand $command): void
    {
        $context = [
            'projectId' => (string) $command->project->id,
            'bridgeId' => (string) $command->bridgeId,
            'sessionId' => (string) $command->sessionId,
        ];
        $run = $this->workerRuns->findLatestOfSessionOnBridge($command->project, $command->bridgeId, $command->sessionId);
        if (null === $run) {
            $this->logger->info('bridge.ask_resume_skipped', $context + ['reason' => 'no_run']);

            return;
        }
        // The close queues a resume, and the end of a run that still ran then queues one too.
        if ($this->bridgeCommands->hasResumeOfRunSince($run, $command->askClosedAt)) {
            $this->logger->info('bridge.ask_resume_skipped', $context + ['runId' => (string) $run->id, 'reason' => 'already_resumed']);

            return;
        }

        try {
            ($this->requestCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::ResumeRun, null, cause: BridgeCommandCause::AskClosed));
        } catch (DomainErrors $e) {
            $this->logger->info('bridge.ask_resume_skipped', $context + ['runId' => (string) $run->id, 'reason' => implode(',', $e->errors)]);
        }
    }
}
