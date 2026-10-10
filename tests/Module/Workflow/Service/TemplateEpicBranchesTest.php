<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\EpicBranches;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateEpicBranchesTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_bound_project_gets_the_epic_branch_of_its_stored_copy(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('epic-branches-bound');
        $binding = $this->bindLifecycle($project);
        $binding->definition = [...$binding->definition, 'epicBranch' => 'feature/epic-{number}'];
        $this->em()->flush();

        self::assertSame('feature/epic-12', $this->branches()->of($project->requireId(), 12));
    }

    public function test_a_copy_with_no_epic_branch_has_none(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('epic-branches-none');
        $binding = $this->bindLifecycle($project);
        $definition = $binding->definition;
        unset($definition['epicBranch']);
        $binding->definition = $definition;
        $this->em()->flush();

        self::assertNull($this->branches()->of($project->requireId(), 12));
    }

    public function test_a_shipped_template_names_the_default_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('epic-branches-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        self::assertSame('epic/12', $this->branches()->of($project->requireId(), 12));
    }

    public function test_an_unbound_project_gets_the_epic_branch_of_the_simple_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('epic-branches-unbound');

        self::assertSame('epic/12', $this->branches()->of($project->requireId(), 12));
    }

    private function branches(): EpicBranches
    {
        $branches = self::getContainer()->get(EpicBranches::class);
        self::assertInstanceOf(EpicBranches::class, $branches);

        return $branches;
    }
}
