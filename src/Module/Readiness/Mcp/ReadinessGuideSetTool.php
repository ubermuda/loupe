<?php

declare(strict_types=1);

namespace App\Module\Readiness\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Readiness\Command\UpdateReadinessSettingsCommand;
use App\Module\Readiness\Command\UpdateReadinessSettingsHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[McpTool(name: self::NAME, description: 'Show or hide the readiness checklist on the Workshop of the project, the same switch as the Agent readiness tab of the project settings. The result says whether the guide shows, and when it was hidden.')]
final readonly class ReadinessGuideSetTool
{
    use ResolvesBoundProject;

    public const string NAME = 'readiness_guide_set';

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private AuthorizationCheckerInterface $authorization,
        private UpdateReadinessSettingsHandler $updateSettings,
    ) {
    }

    /**
     * @param bool $shown whether the Workshop shows the readiness checklist
     *
     * @return array{shown: bool, hiddenAt: string|null}
     */
    public function __invoke(bool $shown): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);
            if (!$this->authorization->isGranted(McpBoundProjectVoter::PROJECT_WRITE, $project)) {
                throw new ToolCallException('This connection cannot change this project.');
            }

            ($this->updateSettings)(new UpdateReadinessSettingsCommand($project, $shown));

            return [
                'shown' => null === $project->readinessGuideHiddenAt,
                'hiddenAt' => $project->readinessGuideHiddenAt?->format(\DATE_ATOM),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The readiness guide could not be changed. The error has been logged.', previous: $e);
        }
    }
}
