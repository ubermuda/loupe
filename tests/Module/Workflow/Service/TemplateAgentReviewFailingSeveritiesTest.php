<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\AgentReviewFailingSeverities;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateAgentReviewFailingSeveritiesTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_bound_project_gets_the_failing_severities_of_its_stored_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('failing-severities-bound');
        $binding = $this->bindLifecycle($project);
        $binding->definition = [...$binding->definition, 'agentReviewFailingSeverities' => ['nit', 'important']];
        $this->em()->flush();

        self::assertSame(['important', 'nit'], $this->severities()->of($project->requireId()));
    }

    public function test_a_copy_with_no_failing_severities_fails_on_an_important_finding(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('failing-severities-none');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        self::assertSame(['important'], $this->severities()->of($project->requireId()));
    }

    public function test_the_shipped_lifecycle_copy_fails_on_an_important_finding(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('failing-severities-lifecycle');
        $this->bindLifecycle($project);

        self::assertSame(['important'], $this->severities()->of($project->requireId()));
    }

    public function test_an_unbound_project_fails_on_an_important_finding(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('failing-severities-unbound');

        self::assertSame(['important'], $this->severities()->of($project->requireId()));
    }

    private function severities(): AgentReviewFailingSeverities
    {
        $severities = self::getContainer()->get(AgentReviewFailingSeverities::class);
        self::assertInstanceOf(AgentReviewFailingSeverities::class, $severities);

        return $severities;
    }
}
