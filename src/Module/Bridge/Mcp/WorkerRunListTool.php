<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListWorkerRunsCommand;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
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
#[McpTool(name: self::NAME, description: 'List the worker runs of this project, newest first. A worker run is one run of a CLI bridge worker on one card. Filter by states, a list of run states such as failed, gave-up, blocked, timed-out or lost. An unknown state is refused, and the error lists the valid states. You can also filter by cardNumber, by rule, the name of the bridge rule that ran the worker, and by bridgeId. search matches words in the card number, the rule name and the output, or a whole run id. endedAfter and endedBefore take an ISO 8601 date, or a date and a time, and both bounds are inclusive. A date with no time reads as midnight UTC, so pass the next day as endedBefore to include a whole day. A time with no offset reads as UTC. The bounds read the end of a run. A run with no recorded end, such as an open, timed-out or lost run, counts at the time its first report arrived. Each row has runId, runKey (the id the bridge gave the run), kind (worker, interactive or command; a command run runs a command with no agent, so it has no session), cardId, cardNumber, rule, state, sessionId (the claude session of the worker), resumeIndex and resumeCap (the place of the run in its series of resumes, and the limit the rule sets), startedAt, endedAt, exitCode, bridgeId, pendingCommand and reason. pendingCommand is the command that waits for the bridge, with its commandId, its kind (resume-run, stop-run or rerun-command) and its state, or null. reason is the failure reason, or else the last line of the output, cut to 300 characters, or null. The response is paginated: pass page to walk further, and keep going while hasMore is true. perPage defaults to 20, with a maximum of 100. worker_run_get reads one run and its series in full.')]
final readonly class WorkerRunListTool
{
    public const string NAME = 'worker_run_list';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ListWorkerRunsHandler $listRuns,
        private WorkerRunPayload $payload,
    ) {
    }

    /**
     * @param string[]|null $states      only runs in one of these states, such as failed, gave-up or timed-out
     * @param int|null      $cardNumber  only the runs of the card with this number
     * @param string|null   $rule        only the runs of the bridge rule with this name
     * @param string|null   $bridgeId    only the runs of this bridge; bridge_list gives the ids
     * @param string|null   $endedAfter  only runs that ended at or after this ISO 8601 date or time
     * @param string|null   $endedBefore only runs that ended at or before this ISO 8601 date or time
     * @param string|null   $search      words to find in the card number, the rule name and the output, or a whole run id
     * @param int           $page        the 1-based page to read
     * @param int           $perPage     how many runs to return per page
     *
     * @return array{runs: list<WorkerRunRow>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(?array $states = null, #[Schema(minimum: 1)] ?int $cardNumber = null, ?string $rule = null, ?string $bridgeId = null, ?string $endedAfter = null, ?string $endedBefore = null, ?string $search = null, int $page = 1, int $perPage = ListWorkerRunsHandler::PER_PAGE): array
    {
        try {
            $project = $this->subjects->requireProject();
            $search = null === $search ? '' : trim($search);
            $rule = null === $rule ? '' : trim($rule);

            $view = ($this->listRuns)(new ListWorkerRunsCommand(
                $project,
                new WorkerRunListQuery(
                    page: $page,
                    search: '' === $search ? null : $search,
                    bridgeId: $this->subjects->optionalBridgeId($bridgeId),
                    states: $this->subjects->optionalStates($states),
                    cardNumber: $cardNumber,
                    rule: '' === $rule ? null : $rule,
                    endedAfter: $this->subjects->optionalTime($endedAfter, 'endedAfter'),
                    endedBefore: $this->subjects->optionalTime($endedBefore, 'endedBefore'),
                ),
                $perPage,
            ));

            return [
                'runs' => array_map(
                    fn (WorkerRunListItem $item): array => $this->payload->forRow($item->run, $item->control?->pendingCommand),
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
