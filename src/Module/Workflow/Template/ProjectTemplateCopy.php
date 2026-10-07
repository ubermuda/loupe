<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Repository\WorkflowBindingRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the template copy a project stored when it was bound, with the app rules after its own. */
#[AsAlias(TemplateSource::class)]
final readonly class ProjectTemplateCopy implements TemplateSource
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private TemplateParser $parser,
        private AppRules $appRules,
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): Template
    {
        $binding = $this->workflowBindings->findOneByProjectId($projectId) ?? throw new TemplateMissing($projectId);

        return $this->appRules->appendTo($this->parser->parseStored($binding->definition));
    }
}
