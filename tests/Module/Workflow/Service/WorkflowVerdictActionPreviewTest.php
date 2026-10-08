<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\VerdictActionPreview;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Service\WorkflowVerdictActionPreview;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\WorkflowProjects;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkflowVerdictActionPreviewTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('verdict-preview');
    }

    public function test_the_container_wires_the_workflow_preview_to_the_board_port(): void
    {
        self::assertInstanceOf(WorkflowVerdictActionPreview::class, self::getContainer()->get(VerdictActionPreview::class));
    }

    /** @return iterable<string, array{CardVerdictKind}> */
    public static function kinds(): iterable
    {
        foreach (CardVerdictKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[DataProvider('kinds')]
    public function test_the_review_write_is_listed_while_its_opt_in_is_on(CardVerdictKind $kind): void
    {
        $this->bindLifecycle($this->project);
        $this->boardAutomation()->settingsForUpdate($this->project)->postWidgetReviews = true;
        $this->em()->flush();

        self::assertSame(['post-review'], $this->preview()->actionsFor($this->project, $kind));
    }

    public function test_the_review_write_is_not_listed_with_its_opt_in_off(): void
    {
        $this->bindLifecycle($this->project);
        $this->boardAutomation()->settingsForUpdate($this->project)->siteReviewCheck = true;
        $this->em()->flush();

        self::assertSame([], $this->preview()->actionsFor($this->project, CardVerdictKind::Approve));
    }

    public function test_the_check_rule_is_never_listed(): void
    {
        $this->bindLifecycle($this->project);
        $settings = $this->boardAutomation()->settingsForUpdate($this->project);
        $settings->postWidgetReviews = true;
        $settings->siteReviewCheck = true;
        $this->em()->flush();

        self::assertNotContains('site-review-check', $this->preview()->actionsFor($this->project, CardVerdictKind::Comment));
    }

    public function test_with_the_automation_off_nothing_is_listed(): void
    {
        $this->bindLifecycle($this->project);
        $settings = $this->boardAutomation()->settingsForUpdate($this->project);
        $settings->postWidgetReviews = true;
        $settings->enabled = false;
        $this->em()->flush();

        self::assertSame([], $this->preview()->actionsFor($this->project, CardVerdictKind::Approve));
    }

    public function test_a_project_with_no_template_lists_nothing(): void
    {
        $this->boardAutomation()->settingsForUpdate($this->project)->postWidgetReviews = true;
        $this->em()->flush();

        self::assertSame([], $this->preview()->actionsFor($this->project, CardVerdictKind::Approve));
    }

    public function test_a_template_without_the_verdict_rule_lists_nothing(): void
    {
        $this->boardAutomation()->settingsForUpdate($this->project)->postWidgetReviews = true;
        $this->em()->flush();
        $templates = $this->createStub(TemplateSource::class);
        $templates->method('forProject')->willReturn(new Template('bare', 1, [], [], [], [10], 120));
        $automation = self::getContainer()->get(WorkflowAutomation::class);
        self::assertInstanceOf(WorkflowAutomation::class, $automation);

        $preview = new WorkflowVerdictActionPreview($automation, $templates, $this->boardAutomation());

        self::assertSame([], $preview->actionsFor($this->project, CardVerdictKind::Approve));
    }

    private function preview(): VerdictActionPreview
    {
        $preview = self::getContainer()->get(VerdictActionPreview::class);
        self::assertInstanceOf(VerdictActionPreview::class, $preview);

        return $preview;
    }

    private function boardAutomation(): BoardAutomation
    {
        $automation = self::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);

        return $automation;
    }
}
