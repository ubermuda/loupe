<?php

declare(strict_types=1);

namespace App\Module\Project\Mcp;

use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Command\UpdateProjectCommand;
use App\Module\Project\Command\UpdateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\ProjectEventType;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[McpTool(name: self::NAME, description: 'Change the name, description, domain or search language of the project, the same settings as the project settings page. An argument you leave out keeps its value. An empty description or domain clears it. A new name also changes the project slug, so a reference to the old slug, such as a bridge rule file, stops matching. The result gives the project after the change.')]
final readonly class ProjectUpdateTool
{
    use ResolvesBoundProject;

    public const string NAME = 'project_update';

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private AuthorizationCheckerInterface $authorization,
        private UpdateProjectHandler $updateProject,
        private ProjectToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string|null $name           the new name of the project, at most 100 characters
     * @param string|null $description    what the project is for, at most 500 characters; an empty string clears it
     * @param string|null $domain         the domain of the site the project reviews, at most 255 characters; an empty string clears it
     * @param string|null $searchLanguage the language a new document of the project is searched in, such as english or simple
     *
     * @return array{id: string, slug: string|null, name: string, description: string|null, domain: string|null, searchLanguage: string}
     */
    public function __invoke(?string $name = null, ?string $description = null, ?string $domain = null, ?string $searchLanguage = null): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);
            if (!$this->authorization->isGranted(McpBoundProjectVoter::PROJECT_WRITE, $project)) {
                throw new ToolCallException('This connection cannot change this project.');
            }

            $newName = null === $name ? $project->name : trim($name);
            if ('' === $newName) {
                throw new ToolCallException('name: A project name must not be blank.');
            }
            if (null !== $name && mb_strlen($newName) > Project::MAX_NAME_LENGTH) {
                throw new ToolCallException(\sprintf('name: A project name must be at most %d characters.', Project::MAX_NAME_LENGTH));
            }
            $newDomain = null === $domain ? $project->domain : self::cleared($domain);
            if (null !== $domain && mb_strlen($newDomain ?? '') > Project::MAX_DOMAIN_LENGTH) {
                throw new ToolCallException(\sprintf('domain: A domain must be at most %d characters.', Project::MAX_DOMAIN_LENGTH));
            }
            $newDescription = null === $description ? $project->description : self::cleared($description);
            if (null !== $description && mb_strlen($newDescription ?? '') > Project::MAX_DESCRIPTION_LENGTH) {
                throw new ToolCallException(\sprintf('description: A description must be at most %d characters.', Project::MAX_DESCRIPTION_LENGTH));
            }
            $newLanguage = null === $searchLanguage
                ? $project->searchLanguage
                : (SearchLanguage::tryFrom($searchLanguage) ?? throw new ToolCallException(\sprintf('searchLanguage: Unknown search language "%s". Use one of: %s.', $searchLanguage, implode(', ', SearchLanguage::values()))));

            $saved = ($this->updateProject)(new UpdateProjectCommand(
                project: $project,
                name: $newName,
                domain: $newDomain,
                searchLanguage: $newLanguage,
                actor: ProjectEventType::ACTOR_AGENT,
                description: $newDescription,
            ));

            return [
                'id' => (string) $saved->id,
                'slug' => $saved->slug,
                'name' => $saved->name,
                'description' => $saved->description,
                'domain' => $saved->domain,
                'searchLanguage' => $saved->searchLanguage->value,
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The project could not be changed. The error has been logged.', previous: $e);
        }
    }

    private static function cleared(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
