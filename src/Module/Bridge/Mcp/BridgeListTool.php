<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Bridge\Command\ListOpenWorkerRunsCommand;
use App\Module\Bridge\Command\ListOpenWorkerRunsHandler;
use App\Module\Bridge\View\AgentConnection;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the bridges that follow the project.
 *
 * @phpstan-type OpenRun array{runId: string, subjectType: string, subjectId: string, cardNumber: ?int, workKind: ?string, state: string}
 * @phpstan-type BridgeRow array{bridgeId: string, name: ?string, requestedName: ?string, liveness: 'live'|'quiet', lastSeenAt: ?string, cliVersion: string, pauseRequested: bool, pausedReported: ?bool, takesCommands: bool, takesReruns: bool, workerPools: list<array{name: string, size: int, inUse: int, queued: int}>|null, workerPoolsReportedAt: ?string, openRuns: list<OpenRun>}
 */
#[McpTool(name: self::NAME, description: 'List the CLI bridges that follow this project. A bridge runs the workers of the project on a machine. Each bridge has bridgeId, name, requestedName, liveness, lastSeenAt, cliVersion, pauseRequested, pausedReported, takesCommands, takesReruns, workerPools, workerPoolsReportedAt and openRuns. name is the name the bridge holds, or null. requestedName is the name its last heartbeat asked for, or null. A requestedName with a null name means another bridge of the owner already holds that name. liveness is live while the bridge sends its heartbeat, and quiet when it missed several. lastSeenAt is the time of its last heartbeat. pauseRequested says that a person asked the bridge to start no new work. pausedReported says whether the bridge reported that it is paused, or is null when it reported nothing. takesCommands is false for an older bridge, which cannot resume or stop a run. takesReruns is false for a bridge that cannot run a failed command run again. workerPools lists each pool of the bridge with its name, size, inUse and queued counts, as its last heartbeat reported them, or is null. openRuns lists the queued, resumed, preparing, running and stopping runs of this project on the bridge, each with runId, subjectType, subjectId, cardNumber, workKind and state. subjectType names what the run is about, such as card, and subjectId is its id. cardNumber is null when the subject is no card. workKind is the kind of the work request the run ran, or null.')]
final readonly class BridgeListTool
{
    public const string NAME = 'bridge_list';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ListAgentsHandler $listAgents,
        private ListOpenWorkerRunsHandler $listOpenRuns,
    ) {
    }

    /**
     * The list is wrapped in a `bridges` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object.
     *
     * @return array{bridges: list<BridgeRow>}
     */
    public function __invoke(): array
    {
        try {
            $project = $this->subjects->requireProject();
            $connections = ($this->listAgents)(new ListAgentsCommand($project))->connections;

            $openRuns = [];
            foreach (($this->listOpenRuns)(new ListOpenWorkerRunsCommand($project))->runs as $run) {
                if (null !== $run->bridgeId) {
                    $openRuns[$run->bridgeId->toRfc4122()][] = ['runId' => (string) $run->id, 'subjectType' => $run->subjectType, 'subjectId' => $run->subjectId->toRfc4122(), 'cardNumber' => $run->cardNumber, 'workKind' => $run->workKind, 'state' => $run->state->value];
                }
            }

            return ['bridges' => array_map(
                static fn (AgentConnection $connection): array => [
                    'bridgeId' => $connection->bridge->id->toRfc4122(),
                    'name' => $connection->bridge->name,
                    'requestedName' => $connection->bridge->requestedName,
                    'liveness' => $connection->status->quiet ? 'quiet' : 'live',
                    'lastSeenAt' => $connection->status->lastSeenAt?->format(\DATE_ATOM),
                    'cliVersion' => $connection->bridge->cliVersion,
                    'pauseRequested' => $connection->bridge->pauseRequested,
                    'pausedReported' => $connection->bridge->pausedReported,
                    'takesCommands' => $connection->bridge->takesCommands(),
                    'takesReruns' => $connection->bridge->takesReruns(),
                    'workerPools' => $connection->bridge->workerPools,
                    'workerPoolsReportedAt' => $connection->bridge->workerPoolsReportedAt?->format(\DATE_ATOM),
                    'openRuns' => $openRuns[$connection->bridge->id->toRfc4122()] ?? [],
                ],
                $connections,
            )];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The bridges could not be read. The error has been logged.', previous: $e);
        }
    }
}
