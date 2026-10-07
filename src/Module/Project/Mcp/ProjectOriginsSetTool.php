<?php

declare(strict_types=1);

namespace App\Module\Project\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Command\UpdateProjectAllowedOriginsCommand;
use App\Module\Project\Command\UpdateProjectAllowedOriginsHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Project\Service\SiteOrigins;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[McpTool(name: self::NAME, description: 'Replace the list of site origins the sign-in widget of the project may run on, the same list as the allowed origins of the project settings. The list you pass replaces the whole stored list, and an empty list clears it. An entry is an origin with no path, such as https://staging.example.com, or a wildcard of one label, such as https://*.example.com. Plain http works on localhost only. A project allows 20 origins at most. The result gives the list as Loupe stored it.')]
final readonly class ProjectOriginsSetTool
{
    use ResolvesBoundProject;

    public const string NAME = 'project_origins_set';

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private AuthorizationCheckerInterface $authorization,
        private UpdateProjectAllowedOriginsHandler $updateOrigins,
        private ProjectToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * `string[]` not `list<string>`, because the SDK parses only `T[]` and `array<T>`.
     *
     * @param string[] $origins every origin the widget may run on, which replaces the stored list
     *
     * @return array{origins: list<string>}
     */
    public function __invoke(array $origins): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);
            if (!$this->authorization->isGranted(McpBoundProjectVoter::PROJECT_WRITE, $project)) {
                throw new ToolCallException('This connection cannot change this project.');
            }

            foreach ($origins as $origin) {
                if (!\is_string($origin)) {
                    throw new ToolCallException('origins: Each entry must be a string.');
                }
                if (1 === preg_match('/\R/', $origin)) {
                    throw new ToolCallException('origins: An entry must not contain a line break. Pass each origin as its own entry.');
                }
            }
            $text = implode("\n", $origins);
            if (mb_strlen($text) > SiteOrigins::MAX_TEXT_LENGTH) {
                throw new ToolCallException(\sprintf('origins: The origins must be at most %d characters together.', SiteOrigins::MAX_TEXT_LENGTH));
            }

            ($this->updateOrigins)(new UpdateProjectAllowedOriginsCommand($project, $text));

            return ['origins' => $project->allowedOrigins];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The allowed origins could not be changed. The error has been logged.', previous: $e);
        }
    }
}
