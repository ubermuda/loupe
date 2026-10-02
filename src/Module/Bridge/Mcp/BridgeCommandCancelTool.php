<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\CancelBridgeCommandCommand;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Withdraws the command that waits on one worker run.
 *
 * @phpstan-import-type Refusal from BridgeCommandRefusals
 */
#[McpTool(name: self::NAME, description: 'Withdraw the resume or stop command that waits on a worker run, before its bridge reads it. Pass runId, from worker_run_list. results has one row. A row with outcome cancelled carries commandId, the id of the withdrawn command. A row with outcome refused carries code and message. The codes are: not-found (no run of this project has this id) and nothing-pending (no command waits on the run, or its bridge already read it).')]
final readonly class BridgeCommandCancelTool
{
    public const string NAME = 'bridge_command_cancel';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private CancelBridgeCommandHandler $cancelCommand,
        private BridgeCommandRefusals $refusals,
    ) {
    }

    /**
     * @param string $runId the id of the worker run whose command to withdraw, from worker_run_list
     *
     * @return array{results: list<array{runId: string, outcome: 'cancelled', commandId: string}|Refusal>}
     */
    public function __invoke(string $runId): array
    {
        try {
            $run = $this->subjects->findRun($this->subjects->parseRunId($runId), McpBoundProjectVoter::WORKER_RUN_WRITE);
            if (null === $run) {
                return ['results' => [$this->refusals->notFound($runId)]];
            }

            try {
                $command = ($this->cancelCommand)(new CancelBridgeCommandCommand($run, $this->subjects->requireUser()));

                return ['results' => [['runId' => $runId, 'outcome' => 'cancelled', 'commandId' => (string) $command->id]]];
            } catch (DomainErrors $e) {
                return ['results' => [$this->refusals->refused($runId, $e)]];
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The command could not be withdrawn. The error has been logged.', previous: $e);
        }
    }
}
