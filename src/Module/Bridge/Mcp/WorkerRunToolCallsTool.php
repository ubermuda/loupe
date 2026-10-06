<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListWorkerRunToolCallsCommand;
use App\Module\Bridge\Command\ListWorkerRunToolCallsHandler;
use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the tool calls of one worker run, a page at a time.
 *
 * @phpstan-type ToolCallRow array{seq: int, tool: string, startedAt: string, durationMs: ?int, isError: ?bool, inSubagent: bool, backgroundId: ?string, waitsOn: ?string, signatures: list<string>, fullText: ?string}
 */
#[McpTool(name: self::NAME, description: 'List the tool calls of one worker run, in the order the worker made them. The bridge reads the calls from the stream of the worker and sends them when the run ends, so an open run, or a run of an older bridge, can have none. Each call has seq (its place in the run, from 1), tool (such as Bash, Read or Agent), startedAt, durationMs, isError, inSubagent (true when a subagent made the call), backgroundId (the id of the background task the call started), waitsOn (the background id of an earlier call that this call reads), signatures (the program and its subcommand for each shell command, such as git status, or the tool name for any other tool) and fullText. durationMs and isError are null when the stream holds no result of the call. fullText is null unless the project collects the full text of the calls. The response is paginated: pass page to walk further, and keep going while hasMore is true. perPage defaults to 20, with a maximum of 100. worker_run_get gives the timing metrics of the run.')]
final readonly class WorkerRunToolCallsTool
{
    public const string NAME = 'worker_run_tool_calls';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ListWorkerRunToolCallsHandler $listToolCalls,
    ) {
    }

    /**
     * @param string $runId   the id of a worker run of this project, from worker_run_list
     * @param int    $page    the 1-based page to read
     * @param int    $perPage how many calls to return per page
     *
     * @return array{calls: list<ToolCallRow>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(string $runId, int $page = 1, int $perPage = ListWorkerRunToolCallsHandler::PER_PAGE): array
    {
        try {
            $run = $this->subjects->requireRun($runId, McpBoundProjectVoter::WORKER_RUN_READ);
            $view = ($this->listToolCalls)(new ListWorkerRunToolCallsCommand($run, $page, $perPage));

            return [
                'calls' => array_map(static fn (WorkerRunToolCall $call): array => [
                    'seq' => $call->seq,
                    'tool' => $call->tool,
                    'startedAt' => $call->startedAt->format(\DATE_ATOM),
                    'durationMs' => $call->durationMs,
                    'isError' => $call->isError,
                    'inSubagent' => $call->inSubagent,
                    'backgroundId' => $call->backgroundId,
                    'waitsOn' => $call->waitsOn,
                    'signatures' => $call->signatures,
                    'fullText' => $call->fullText,
                ], $view->calls),
                'page' => $view->page,
                'perPage' => $view->perPage,
                'total' => $view->total,
                'hasMore' => $view->page * $view->perPage < $view->total,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The tool calls could not be read. The error has been logged.', previous: $e);
        }
    }
}
