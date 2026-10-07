<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectTemplateCopyTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_it_reads_the_stored_copy_back_as_the_shipped_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('workflow-copy');
        $this->bindLifecycle($project);
        // The copy must come back from the jsonb column, not from the identity map.
        $this->em()->clear();

        $template = $this->copy()->forProject($project->id ?? throw new \LogicException());

        $parser = self::getContainer()->get(TemplateParser::class);
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(TemplateParser::class, $parser);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);
        self::assertEquals($parser->parse($shipped->source('lifecycle')), $template);
    }

    public function test_the_app_rules_come_after_the_rules_of_the_stored_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('workflow-copy-app');
        $this->bindLifecycle($project);
        $this->em()->clear();
        $parser = self::getContainer()->get(TemplateParser::class);
        $bindings = self::getContainer()->get(WorkflowBindingRepository::class);
        self::assertInstanceOf(TemplateParser::class, $parser);
        self::assertInstanceOf(WorkflowBindingRepository::class, $bindings);
        $copy = new ProjectTemplateCopy($bindings, $parser, new AppRules($parser, AppRulesTest::FIXTURE));

        $rules = $copy->forProject($project->id ?? throw new \LogicException())->rules;

        $origins = array_map(static fn (Rule $rule): RuleOrigin => $rule->origin, $rules);
        self::assertSame([RuleOrigin::App, RuleOrigin::App], \array_slice($origins, -2));
        self::assertNotContains(RuleOrigin::App, \array_slice($origins, 0, -2));
        self::assertSame(['app-groom', 'app-tidy'], array_map(static fn (Rule $rule): string => $rule->id, \array_slice($rules, -2)));
    }

    public function test_an_unbound_project_has_no_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('workflow-copy-unbound');
        $projectId = $project->id ?? throw new \LogicException();

        try {
            $this->copy()->forProject($projectId);
            self::fail('An unbound project must throw.');
        } catch (TemplateMissing $e) {
            self::assertTrue($projectId->equals($e->projectId));
        }
    }

    private function copy(): ProjectTemplateCopy
    {
        $copy = self::getContainer()->get(ProjectTemplateCopy::class);
        self::assertInstanceOf(ProjectTemplateCopy::class, $copy);

        return $copy;
    }
}
