<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\RuleBudgets;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\WorkflowRuleBudgets;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkflowRuleBudgetsTest extends KernelTestCase
{
    use ActionScenario;

    public function test_it_reads_the_fires_of_the_rule_on_the_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('rule-budgets-fires');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-review');
        $cardId = $card->id ?? throw new \LogicException('The card is persisted.');
        $state = new WorkflowRuleState($cardId, $project, 'fix-in-review');
        $state->fires = 2;
        $this->em()->persist($state);
        $this->em()->flush();

        self::assertSame(2, $this->budgets()->fires($cardId, 'fix-in-review'));
        self::assertNull($this->budgets()->fires($cardId, 'merge-ready'));
    }

    public function test_it_reads_the_limit_of_the_rule_in_the_template_of_the_project(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('rule-budgets-limit');
        $this->bindLifecycle($project);
        $projectId = $project->id ?? throw new \LogicException('The project is persisted.');

        self::assertSame(3, $this->budgets()->limit($projectId, 'fix-in-review'));
        self::assertNull($this->budgets()->limit($projectId, 'merge-ready'));
        self::assertNull($this->budgets()->limit($projectId, 'no-such-rule'));
    }

    public function test_a_project_with_no_template_has_no_limit(): void
    {
        self::bootKernel();

        self::assertNull($this->budgets()->limit(Uuid::v7(), 'fix-in-review'));
    }

    private function budgets(): RuleBudgets
    {
        return new WorkflowRuleBudgets($this->service(WorkflowRuleStateRepository::class), $this->service(TemplateSource::class));
    }
}
