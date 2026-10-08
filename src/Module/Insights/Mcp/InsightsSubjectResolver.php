<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

/** Resolves the project an analysis tool call acts on. An analysis is looked up inside that project alone. */
final readonly class InsightsSubjectResolver
{
    use ResolvesBoundProject;

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private Security $security,
    ) {
    }

    public function requireReadableProject(): Project
    {
        $project = $this->requireBoundProject($this->projectResolver);

        return $this->security->isGranted(McpBoundProjectVoter::ANALYSIS_READ, $project)
            ? $project
            : throw new ToolCallException('This credential cannot read the analyses of this project.');
    }

    public function requireWritableProject(): Project
    {
        $project = $this->requireBoundProject($this->projectResolver);

        return $this->security->isGranted(McpBoundProjectVoter::ANALYSIS_WRITE, $project)
            ? $project
            : throw new ToolCallException('This credential cannot change the analyses of this project.');
    }
}
