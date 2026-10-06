<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListWorkerRunReadingsCommand;
use App\Module\Bridge\Command\ListWorkerRunReadingsHandler;
use App\Module\Bridge\Command\ShowWorkerRunSeriesCommand;
use App\Module\Bridge\Command\ShowWorkerRunSeriesHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads one worker run with its whole series of resumes.
 *
 * @phpstan-import-type WorkerRunDetail from WorkerRunPayload
 * @phpstan-import-type BridgeCommandRow from WorkerRunPayload
 */
#[McpTool(name: self::NAME, description: 'Read one worker run in full, with every run of its series. A series starts with one run, and each resume of a run starts a new run that continues it. runs lists the whole series, oldest first. When an older run was deleted, its continuations lose their link to it. runs then lists only the branch of the oldest run that remains, and the runs on other branches of the deleted run are not in it. Each run has the fields of a worker_run_list row, usage, model, experiment, variant and metrics included. It also has continuesRunId (the run it resumes, or null), receivedAt (when its first report arrived), failureReason, output (the whole output the bridge reported), and stateChanges (each state the run reached, with the time, oldest first). commands lists every command a person or an agent sent to the bridge for a run of the series, oldest first. Each command has commandId, runId, kind (resume-run, stop-run or rerun-command), state (pending, done, refused, expired or cancelled), reason, requestedAt, expiresAt and settledAt. reason is the reason the requester gave, until the bridge settles the command with a reason of its own.')]
final readonly class WorkerRunGetTool
{
    public const string NAME = 'worker_run_get';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ShowWorkerRunSeriesHandler $showSeries,
        private ListWorkerRunReadingsHandler $listReadings,
        private WorkerRunPayload $payload,
    ) {
    }

    /**
     * @param string $runId the id of a worker run of this project, from worker_run_list
     *
     * @return array{runs: list<WorkerRunDetail>, commands: list<BridgeCommandRow>}
     */
    public function __invoke(string $runId): array
    {
        try {
            $run = $this->subjects->requireRun($runId, McpBoundProjectVoter::WORKER_RUN_READ);
            $view = ($this->showSeries)(new ShowWorkerRunSeriesCommand($run));
            $readings = ($this->listReadings)(new ListWorkerRunReadingsCommand($run->project, $view->runs));

            $pending = [];
            foreach ($view->commands as $command) {
                if (BridgeCommandState::Pending === $command->state) {
                    $pending[(string) $command->workerRun->id] = $command;
                }
            }

            return [
                'runs' => array_map(
                    fn (WorkerRun $run): array => $this->payload->forDetail($run, $pending[(string) $run->id] ?? null, $view->stateChanges[(string) $run->id] ?? [], $readings),
                    $view->runs,
                ),
                'commands' => array_map(
                    $this->payload->forCommand(...),
                    $view->commands,
                ),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The worker run could not be read. The error has been logged.', previous: $e);
        }
    }
}
