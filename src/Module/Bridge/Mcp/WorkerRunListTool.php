<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListWorkerRunReadingsCommand;
use App\Module\Bridge\Command\ListWorkerRunReadingsHandler;
use App\Module\Bridge\Command\ListWorkerRunsCommand;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\View\WorkerRunListItem;
use App\Module\Bridge\View\WorkerRunListQuery;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Reads one page of the project's worker runs.
 *
 * @phpstan-import-type WorkerRunRow from WorkerRunPayload
 */
#[McpTool(name: self::NAME, description: 'List the worker runs of this project, newest first. A worker run is one run of a CLI bridge worker on one card. Filter by states, a list of run states such as failed, gave-up, blocked, timed-out or lost. An unknown state is refused, and the error lists the valid states. You can also filter by cardNumber, by workKind, the kind of the work request the run ran, such as implement or fix, and by bridgeId. search matches words in the card number, the work kind and the output, or a whole run id. endedAfter and endedBefore take an ISO 8601 date, or a date and a time, and both bounds are inclusive. A date with no time reads as midnight UTC, so pass the next day as endedBefore to include a whole day. A time with no offset reads as UTC. The bounds read the end of a run. A run with no recorded end, such as an open, timed-out or lost run, counts at the time its first report arrived. Each row has runId, runKey (the id the bridge gave the run), kind (worker, interactive or command; a command run runs a command with no agent, so it has no session), cardId, cardNumber, workRequestId (the work request the run ran), workKind (the kind of that request, or the name of an interactive session), ruleId (the id of the workflow rule that opened the request), state, sessionId (the claude session of the worker), startedAt, endedAt, exitCode, bridgeId, pendingCommand and reason. pendingCommand is the command that waits for the bridge, with its commandId, its kind (resume-run, stop-run or rerun-command) and its state, or null. reason is the failure reason, or else the last line of the output, cut to 300 characters, or null. usage lists the tokens of each model the run used, with model, source (reported or estimated), inputTokens, outputTokens, cacheReadTokens, cacheWriteTokens and costUsd. costUsd is a decimal string in US dollars, or null when the model has no price. usage is an empty list when the bridge sent no usage. model is the model with the highest cost, or null. experiment and variant name the experiment of the rule that ran the worker, or null. metrics holds the sums of the run: durationMs, costUsd (a number in US dollars), tokensIn, tokensOut, tokensCacheRead and tokensCacheWrite. A sum is null when it is unknown, such as a cost when a model has no price. metrics is null when the run has no metrics row. The response is paginated: pass page to walk further, and keep going while hasMore is true. perPage defaults to 20, with a maximum of 100. worker_run_get reads one run and its series in full.')]
final readonly class WorkerRunListTool
{
    public const string NAME = 'worker_run_list';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ListWorkerRunsHandler $listRuns,
        private ListWorkerRunReadingsHandler $listReadings,
        private WorkerRunPayload $payload,
    ) {
    }

    /**
     * @param string[]|null $states      only runs in one of these states, such as failed, gave-up or timed-out
     * @param int|null      $cardNumber  only the runs of the card with this number
     * @param string|null   $workKind    only the runs of the work requests of this kind, such as implement or fix
     * @param string|null   $bridgeId    only the runs of this bridge; bridge_list gives the ids
     * @param string|null   $endedAfter  only runs that ended at or after this ISO 8601 date or time
     * @param string|null   $endedBefore only runs that ended at or before this ISO 8601 date or time
     * @param string|null   $search      words to find in the card number, the work kind and the output, or a whole run id
     * @param int           $page        the 1-based page to read
     * @param int           $perPage     how many runs to return per page
     *
     * @return array{runs: list<WorkerRunRow>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(?array $states = null, #[Schema(minimum: 1)] ?int $cardNumber = null, ?string $workKind = null, ?string $bridgeId = null, ?string $endedAfter = null, ?string $endedBefore = null, ?string $search = null, int $page = 1, int $perPage = ListWorkerRunsHandler::PER_PAGE): array
    {
        try {
            $project = $this->subjects->requireProject();
            $search = null === $search ? '' : trim($search);
            $workKind = null === $workKind ? '' : trim($workKind);

            $view = ($this->listRuns)(new ListWorkerRunsCommand(
                $project,
                new WorkerRunListQuery(
                    page: $page,
                    search: '' === $search ? null : $search,
                    bridgeId: $this->subjects->optionalBridgeId($bridgeId),
                    states: $this->subjects->optionalStates($states),
                    cardNumber: $cardNumber,
                    workKind: '' === $workKind ? null : $workKind,
                    endedAfter: $this->subjects->optionalTime($endedAfter, 'endedAfter'),
                    endedBefore: $this->subjects->optionalTime($endedBefore, 'endedBefore'),
                ),
                $perPage,
            ));

            $readings = ($this->listReadings)(new ListWorkerRunReadingsCommand($project, array_map(static fn (WorkerRunListItem $item): WorkerRun => $item->run, $view->items)));

            return [
                'runs' => array_map(
                    fn (WorkerRunListItem $item): array => $this->payload->forRow($item->run, $item->control?->pendingCommand, $readings),
                    $view->items,
                ),
                'page' => $view->page,
                'perPage' => $view->perPage,
                'total' => $view->filteredTotal,
                'hasMore' => $view->page * $view->perPage < $view->filteredTotal,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The worker runs could not be read. The error has been logged.', previous: $e);
        }
    }
}
