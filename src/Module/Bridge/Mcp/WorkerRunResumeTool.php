<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Asks the bridges to resume a list of worker runs.
 *
 * @phpstan-import-type Refusal from BridgeCommandRefusals
 */
#[McpTool(name: self::NAME, description: 'Ask the bridge of each run to resume it. A resume continues the claude session of the run as a new run of the same series. Pass runIds, a list of 1 to 50 worker run ids from worker_run_list. The tool treats each run on its own and in order, so a refused run does not stop the others. results has one row per run id, in the same order. A row with outcome resumed carries commandId: the command waits for the bridge, which resumes the run when it reads the command. worker_run_list then shows the command as pendingCommand. A row with outcome refused carries code and message. The codes are: not-found (no run of this project has this id), no-bridge, unknown-bridge, bridge-outdated (the bridge is too old to take commands), pending (a command already waits on the run), not-controllable (an interactive session), no-session, not-resumable (the run did not end, or it succeeded), and card-left (the card left the column of the run). Only a run in the state blocked, gave-up, failed, no-result, unfinished, timed-out, lost, stopped or waiting-for-person can resume.')]
final readonly class WorkerRunResumeTool
{
    public const string NAME = 'worker_run_resume';

    public const int MAX_RUNS = 50;

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private RequestBridgeCommandHandler $requestCommand,
        private BridgeCommandRefusals $refusals,
    ) {
    }

    /**
     * @param string[] $runIds the ids of the worker runs to resume, from worker_run_list
     *
     * @return array{results: list<array{runId: string, outcome: 'resumed', commandId: string}|Refusal>}
     */
    public function __invoke(#[Schema(minItems: 1, maxItems: self::MAX_RUNS)] array $runIds): array
    {
        try {
            if ([] === $runIds || \count($runIds) > self::MAX_RUNS) {
                throw new ToolCallException(\sprintf('Pass from 1 to %d run ids in runIds.', self::MAX_RUNS));
            }

            $parsed = [];
            foreach (array_values($runIds) as $index => $runId) {
                if (!\is_string($runId)) {
                    throw new ToolCallException(\sprintf('runIds[%d] must be a string.', $index));
                }
                $parsed[] = [$runId, $this->subjects->parseRunId($runId)];
            }
            $user = $this->subjects->requireUser();

            $results = [];
            foreach ($parsed as [$runId, $id]) {
                $run = $this->subjects->findRun($id, McpBoundProjectVoter::WORKER_RUN_WRITE);
                if (null === $run) {
                    $results[] = $this->refusals->notFound($runId);
                    continue;
                }

                try {
                    $command = ($this->requestCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::ResumeRun, $user));
                    $results[] = ['runId' => $runId, 'outcome' => 'resumed', 'commandId' => (string) $command->id];
                } catch (DomainErrors $e) {
                    $results[] = $this->refusals->refused($runId, $e);
                }
            }

            return ['results' => $results];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The runs could not be resumed. The error has been logged.', previous: $e);
        }
    }
}
