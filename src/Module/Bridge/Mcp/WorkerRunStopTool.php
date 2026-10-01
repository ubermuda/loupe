<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Asks the bridge to stop one worker run.
 *
 * @phpstan-import-type Refusal from BridgeCommandRefusals
 */
#[McpTool(name: self::NAME, description: 'Ask the bridge of a worker run to stop it. Only a queued, resumed, preparing or running run can stop. A stop also holds the card, so no bridge rule starts new work on it. The hold goes when a person moves the card to another column, or when a run of the card resumes. Pass runId, from worker_run_list, and an optional reason of at most 1000 characters. results has one row. A row with outcome stopped carries commandId: the command waits for the bridge, which stops the run when it reads the command. A row with outcome refused carries code and message. The codes are: not-found (no run of this project has this id), no-bridge, unknown-bridge, bridge-outdated (the bridge is too old to take commands), pending (a command already waits on the run), not-controllable (an interactive session), not-stoppable (the run is not queued, resumed, preparing or running) and reason-too-long.')]
final readonly class WorkerRunStopTool
{
    public const string NAME = 'worker_run_stop';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private RequestBridgeCommandHandler $requestCommand,
        private BridgeCommandRefusals $refusals,
    ) {
    }

    /**
     * @param string      $runId  the id of the worker run to stop, from worker_run_list
     * @param string|null $reason why the run must stop, which a person reads on the run
     *
     * @return array{results: list<array{runId: string, outcome: 'stopped', commandId: string}|Refusal>}
     */
    public function __invoke(string $runId, ?string $reason = null): array
    {
        try {
            $run = $this->subjects->findRun($this->subjects->parseRunId($runId), McpBoundProjectVoter::WORKER_RUN_WRITE);
            if (null === $run) {
                return ['results' => [$this->refusals->notFound($runId)]];
            }

            try {
                $command = ($this->requestCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::StopRun, $this->subjects->requireUser(), $reason));

                return ['results' => [['runId' => $runId, 'outcome' => 'stopped', 'commandId' => (string) $command->id]]];
            } catch (DomainErrors $e) {
                return ['results' => [$this->refusals->refused($runId, $e)]];
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The run could not be stopped. The error has been logged.', previous: $e);
        }
    }
}
