<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\EpicBranches;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** Reads the epic branch from the stored template copy. A project bound to no template gets the value of the Simple template. */
#[AsAlias(EpicBranches::class)]
final class TemplateEpicBranches implements EpicBranches, ResetInterface
{
    private const string UNBOUND_TEMPLATE = 'simple';

    /** @var array<string, Template> */
    private array $templates = [];

    public function __construct(
        private readonly TemplateSource $source,
        private readonly ShippedTemplates $shippedTemplates,
        private readonly TemplateParser $parser,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId, int $number): ?string
    {
        return ($this->templates[$projectId->toRfc4122()] ??= $this->template($projectId))->epicBranchOf($number);
    }

    #[\Override]
    public function reset(): void
    {
        $this->templates = [];
    }

    private function template(Uuid $projectId): Template
    {
        try {
            return $this->source->forProject($projectId);
        } catch (TemplateMissing) {
            return $this->parser->parse($this->shippedTemplates->source(self::UNBOUND_TEMPLATE));
        }
    }
}
