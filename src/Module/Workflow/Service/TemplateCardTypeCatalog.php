<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Service\CardTypeCatalog;
use App\Module\Board\Service\CardTypeDefinition;
use App\Module\Board\Service\CardTypes;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateCardType;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Service\ResetInterface;

/** Reads the card types from the stored template copy. A project bound to no template gets the types of the Simple template. */
#[AsAlias(CardTypeCatalog::class)]
final class TemplateCardTypeCatalog implements CardTypeCatalog, ResetInterface
{
    private const string UNBOUND_TEMPLATE = 'simple';

    /** @var array<string, CardTypes> */
    private array $types = [];

    public function __construct(
        private readonly TemplateSource $templates,
        private readonly ShippedTemplates $shippedTemplates,
        private readonly TemplateParser $parser,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): CardTypes
    {
        $projectId = $project->id ?? throw new \LogicException('The project is not persisted.');

        return $this->types[$projectId->toRfc4122()] ??= self::of($this->template($project));
    }

    #[\Override]
    public function reset(): void
    {
        $this->types = [];
    }

    private function template(Project $project): Template
    {
        try {
            return $this->templates->forProject($project->id ?? throw new \LogicException('The project is not persisted.'));
        } catch (TemplateMissing) {
            return $this->parser->parse($this->shippedTemplates->source(self::UNBOUND_TEMPLATE));
        }
    }

    private static function of(Template $template): CardTypes
    {
        return new CardTypes(
            array_map(
                static fn (TemplateCardType $type): CardTypeDefinition => new CardTypeDefinition($type->key, $type->label, $type->tone, $type->children, $type->lane),
                $template->types,
            ),
            $template->defaultType,
        );
    }
}
