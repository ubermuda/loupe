<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Exception\DomainErrors;
use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Workflow\Contract\LabelTone;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Template\ShippedTemplateChoices;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Slot;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\UnknownTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Runs before the default column seeder, so a template with slots seeds its own columns instead. A project that names no template gets the default one. */
#[AsEventListener(priority: 10)]
final readonly class BindTemplateOnProjectCreating
{
    private const array SLOT_TONES = [
        'next' => LabelTone::Lime,
        'product-design' => LabelTone::Sky,
        'tech-design' => LabelTone::Indigo,
        'implementation' => LabelTone::Purple,
        'in-review' => LabelTone::Amber,
    ];

    public function __construct(
        private ShippedTemplates $shippedTemplates,
        private ShippedTemplateChoices $choices,
        private TemplateParser $parser,
        private BoardColumnSeeder $seeder,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectCreating $event): void
    {
        try {
            $source = $this->shippedTemplates->source($event->workflowTemplate ?? $this->choices->defaultKey());
        } catch (UnknownTemplate) {
            throw new DomainErrors(['workflowTemplate' => 'workflow.bind.error.unknown_template']);
        }
        $template = $this->parser->parse($source);
        $project = $event->project;

        if ([] !== $template->slots) {
            $columns = $this->seeder->seedBetweenBacklogAndDone($project, array_map(
                static fn (Slot $slot): array => [
                    'slug' => '' !== $slot->key ? $slot->key : throw new \LogicException('A slot key is never empty.'),
                    'label' => $slot->label,
                    'tone' => self::SLOT_TONES[$slot->key] ?? LabelTone::Neutral,
                ],
                $template->slots,
            ));
            $event->markColumnsSeeded();

            foreach ($columns as $column) {
                if (null !== $template->slot($column->slug)) {
                    $this->em->persist(new WorkflowSlotLink($project, $column->slug, $column->id));
                }
            }
        }

        $this->em->persist(new WorkflowBinding($project, $template->key, $template->version, $source));
    }
}
