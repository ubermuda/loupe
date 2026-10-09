<?php

declare(strict_types=1);

namespace App\Module\Readiness\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Readiness\Command\DiscoveryRunning;
use App\Module\Readiness\Command\StartDiscoveryCommand;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Workflow\Contract\Actor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: self::NAME, description: 'Start a discovery run on this project. Loupe creates a new tooling card in the Backlog, and a bridge that serves the project runs a read-only worker on it. The worker reads the repository and the workflow, and reports what the project needs before agents work on it. A live bridge must serve the project, the project must have a workflow with board automation on, and only one run can be open at a time. Call readiness_get to follow the run.')]
final readonly class DiscoveryStartTool
{
    use ResolvesBoundProject;

    public const string NAME = 'discovery_start';

    public function __construct(
        private StartDiscoveryHandler $startDiscovery,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /** @return array{cardId: string, cardNumber: int, runId: string, state: string} */
    public function __invoke(): array
    {
        try {
            $run = ($this->startDiscovery)(new StartDiscoveryCommand($this->requireBoundProject($this->projectResolver), Actor::Agent));

            return [
                'cardId' => (string) $run->card->id,
                'cardNumber' => $run->card->number,
                'runId' => (string) $run->id,
                'state' => $run->state->value,
            ];
        } catch (DomainErrors $e) {
            $message = match (array_first($e->errors)) {
                StartDiscoveryHandler::NO_LIVE_BRIDGE => 'No bridge serves this project now. Start a bridge that serves this project, then call discovery_start again.',
                StartDiscoveryHandler::NO_WORKFLOW => 'This project has no workflow, so the workflow cannot ask a bridge for the work. Choose a workflow for this project, then call discovery_start again.',
                StartDiscoveryHandler::AUTOMATION_OFF => 'Board automation is off for this project, so the workflow cannot ask a bridge for the work. Switch on "Run the workflow of the board" on the Automation tab of the project settings, or call automation_settings_update with enabled true, then call discovery_start again.',
                default => 'The request was rejected. The error has been logged.',
            };
            throw new ToolCallException($message, previous: $e);
        } catch (DiscoveryRunning $e) {
            throw new ToolCallException(\sprintf('Discovery already runs on card #%d. Wait until that run ends. readiness_get shows its state.', $e->cardNumber), previous: $e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Discovery could not start. The error has been logged.', previous: $e);
        }
    }
}
