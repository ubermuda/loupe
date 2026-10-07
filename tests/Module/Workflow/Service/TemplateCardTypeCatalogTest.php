<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Entity\LabelTone;
use App\Module\Board\Service\CardTypeDefinition;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Service\TemplateCardTypeCatalog;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateCardTypeCatalogTest extends KernelTestCase
{
    use WorkflowProjects;

    private const array SHIPPED_KEYS = ['feature', 'bug', 'security', 'tooling', 'docs', 'idea', 'epic'];

    public function test_a_bound_project_gets_the_types_of_its_stored_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('card-types-bound');
        $binding = $this->bindLifecycle($project);
        $definition = $binding->definition;
        self::assertIsArray($definition['types']);
        self::assertIsArray($definition['types'][1]);
        $definition['types'][1]['tone'] = 'pink';
        $binding->definition = $definition;
        $this->em()->flush();

        $types = $this->catalog()->forProject($project);

        self::assertSame(self::SHIPPED_KEYS, array_map(static fn (CardTypeDefinition $type): string => $type->key, $types->all));
        self::assertSame('feature', $types->defaultKey);
        self::assertSame(LabelTone::Pink, $types->get('bug')->tone);
        self::assertEquals(new CardTypeDefinition('epic', 'board.card.type.epic', LabelTone::Blue, true, true), $types->get('epic'));
    }

    public function test_a_simple_project_gets_the_simple_types(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('card-types-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        $types = $this->catalog()->forProject($project);

        self::assertSame(self::SHIPPED_KEYS, array_map(static fn (CardTypeDefinition $type): string => $type->key, $types->all));
    }

    public function test_an_unbound_project_gets_the_types_of_the_simple_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('card-types-unbound');

        $types = $this->catalog()->forProject($project);

        self::assertSame(self::SHIPPED_KEYS, array_map(static fn (CardTypeDefinition $type): string => $type->key, $types->all));
        self::assertSame('feature', $types->default()->key);
    }

    public function test_a_reset_forgets_the_types_it_read(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('card-types-reset');
        $catalog = $this->catalog();
        self::assertSame(LabelTone::Amber, $catalog->forProject($project)->get('bug')->tone);
        $binding = $this->bindLifecycle($project);
        $definition = $binding->definition;
        self::assertIsArray($definition['types']);
        self::assertIsArray($definition['types'][1]);
        $definition['types'][1]['tone'] = 'pink';
        $binding->definition = $definition;
        $this->em()->flush();

        self::assertSame(LabelTone::Amber, $catalog->forProject($project)->get('bug')->tone);
        $catalog->reset();
        self::assertSame(LabelTone::Pink, $catalog->forProject($project)->get('bug')->tone);
    }

    /** No service reads the catalog yet, so the container removes it. The test builds it from the services it needs. */
    private function catalog(): TemplateCardTypeCatalog
    {
        $container = self::getContainer();

        return new TemplateCardTypeCatalog(
            $container->get(TemplateSource::class),
            $container->get(ShippedTemplates::class),
            $container->get(TemplateParser::class),
        );
    }
}
