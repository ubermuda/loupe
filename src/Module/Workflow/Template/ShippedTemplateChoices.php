<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Project\Service\WorkflowTemplateChoice;
use App\Module\Project\Service\WorkflowTemplateChoices;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(WorkflowTemplateChoices::class)]
final readonly class ShippedTemplateChoices implements WorkflowTemplateChoices
{
    public function __construct(
        private ShippedTemplates $shippedTemplates,
    ) {
    }

    #[\Override]
    public function choices(): array
    {
        return array_map(
            static fn (string $key): WorkflowTemplateChoice => new WorkflowTemplateChoice(
                $key,
                \sprintf('workflow.template.%s.label', $key),
                \sprintf('workflow.template.%s.description', $key),
            ),
            $this->shippedTemplates->keys(),
        );
    }

    #[\Override]
    public function defaultKey(): string
    {
        return 'simple';
    }
}
