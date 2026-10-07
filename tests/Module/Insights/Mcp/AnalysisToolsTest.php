<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Mcp;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Mcp\AnalysisGetTool;
use App\Module\Insights\Mcp\AnalysisReportTool;
use App\Module\Insights\Repository\ProposalRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AnalysisToolsTest extends KernelTestCase
{
    use InsightsScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_get_answers_a_waiting_analysis_with_no_cost(): void
    {
        $project = $this->scenarioProject('analysis-get-waiting');
        $analysis = $this->seedAnalysis($this->em(), $project);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame([
            'id' => (string) $analysis->id,
            'topic' => 'cost',
            'scope' => ['range' => 'ninety-days'],
            'question' => null,
            'model' => 'sonnet',
            'effort' => 'medium',
            'state' => 'waiting',
            'reason' => null,
            'createdAt' => '2026-10-07T09:00:00+00:00',
            'finishedAt' => null,
            'documentId' => null,
            'costUsd' => null,
            'proposals' => [],
        ], $this->getTool()((string) $analysis->id));
    }

    public function test_get_answers_the_cost_of_the_runs_and_the_proposals(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-get-done');
        $analysis = $this->seedAnalysis($em, $project);
        $document = $this->seedDocument($em, $project);
        $analysis->complete($document->id ?? throw new \LogicException(), new \DateTimeImmutable('2026-10-07 10:00:00'));
        $em->flush();
        $card = $this->seedProposal($em, $analysis);
        $rule = $this->seedProposal($em, $analysis, ProposalKind::BucketRule, 1);
        $this->fact($project, $analysis, 1_250_000);
        $this->fact($project, $analysis, 250_000);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->getTool()((string) $analysis->id);

        self::assertSame('done', $result['state']);
        self::assertSame((string) $document->id, $result['documentId']);
        self::assertSame('2026-10-07T10:00:00+00:00', $result['finishedAt']);
        self::assertSame(1.5, $result['costUsd']);
        self::assertSame([
            [
                'id' => (string) $card->id,
                'kind' => 'card',
                'title' => 'Cache the dependencies',
                'body' => 'Each run installs them again.',
                'payload' => null,
                'estimatedSaving' => 'About $4 a week',
                'state' => 'proposed',
                'dismissReason' => null,
                'cardId' => null,
            ],
            [
                'id' => (string) $rule->id,
                'kind' => 'bucket-rule',
                'title' => 'Cache the dependencies',
                'body' => 'Each run installs them again.',
                'payload' => null,
                'estimatedSaving' => 'About $4 a week',
                'state' => 'proposed',
                'dismissReason' => null,
                'cardId' => null,
            ],
        ], $result['proposals']);
    }

    public function test_get_refuses_an_analysis_of_another_project_like_an_unknown_one(): void
    {
        $owner = $this->scenarioProject('analysis-get-scope');
        $other = $this->project($this->em(), $owner->owner, 'Other analysis-get-scope');
        $foreign = $this->seedAnalysis($this->em(), $other);
        $this->actAsMcpTokenBoundTo($owner);

        self::assertSame(
            \sprintf('Analysis "%s" not found in this project. Pass the analysis id from your work request.', $foreign->id),
            $this->refusal(fn () => $this->getTool()((string) $foreign->id)),
        );
        self::assertSame(
            'Analysis "not-a-uuid" not found in this project. Pass the analysis id from your work request.',
            $this->refusal(fn () => $this->getTool()('not-a-uuid')),
        );
    }

    public function test_get_refuses_an_unbound_token(): void
    {
        $project = $this->scenarioProject('analysis-get-unbound');
        $analysis = $this->seedAnalysis($this->em(), $project);
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->getTool()((string) $analysis->id);
    }

    public function test_report_completes_the_analysis_with_its_proposals(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-report');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $document = $this->seedDocument($em, $project);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->reportTool()((string) $analysis->id, (string) $document->id, [
            ['kind' => 'card', 'title' => ' Cache the dependencies ', 'body' => 'Each run installs them again.', 'estimatedSaving' => 'About $4 a week'],
            ['kind' => 'bucket-rule', 'title' => 'Group the lint runs', 'body' => 'They share one cause.', 'payload' => ['pattern' => 'Bash:lint*', 'bucket' => 'lint']],
        ]);

        self::assertSame('done', $result['state']);
        self::assertSame((string) $document->id, $result['documentId']);
        self::assertSame(['Cache the dependencies', 'Group the lint runs'], array_column($result['proposals'], 'title'));
        self::assertSame([null, ['pattern' => 'Bash:lint*', 'bucket' => 'lint']], array_column($result['proposals'], 'payload'));

        $em->clear();
        $stored = $this->proposals()->findBy([], ['position' => 'ASC']);
        $mine = array_values(array_filter($stored, static fn ($proposal): bool => (string) $proposal->analysis->id === (string) $analysis->id));
        self::assertCount(2, $mine);
        self::assertSame('About $4 a week', $mine[0]->estimatedSaving);
    }

    public function test_report_takes_no_proposals(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-report-empty');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $document = $this->seedDocument($em, $project);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->reportTool()((string) $analysis->id, (string) $document->id);

        self::assertSame('done', $result['state']);
        self::assertSame([], $result['proposals']);
    }

    public function test_report_turns_the_domain_errors_into_readable_text(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-report-refused');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            "proposals: A proposal title must not be blank.\ndocumentId: The document must be a document of this project. Create the report with document_create first.",
            $this->refusal(fn () => $this->reportTool()((string) $analysis->id, (string) Uuid::v7(), [['kind' => 'card', 'title' => ' ', 'body' => 'Body.']])),
        );
    }

    public function test_report_refuses_a_bucket_rule_without_a_valid_payload(): void
    {
        $project = $this->scenarioProject('analysis-report-rule');
        $analysis = $this->seedAnalysis($this->em(), $project, AnalysisState::Running);
        $document = $this->seedDocument($this->em(), $project);
        $this->actAsMcpTokenBoundTo($project);

        self::assertStringStartsWith(
            'proposals: A bucket-rule proposal needs a payload with pattern',
            $this->refusal(fn () => $this->reportTool()((string) $analysis->id, (string) $document->id, [['kind' => 'bucket-rule', 'title' => 'T', 'body' => 'B', 'payload' => ['pattern' => 'Bash:lint*', 'bucket' => 'Bad Name']]])),
        );
    }

    public function test_report_refuses_a_finished_analysis(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-report-finished');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Failed);
        $document = $this->seedDocument($em, $project);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            'analysisId: The analysis is already done or failed, so it takes no report.',
            $this->refusal(fn () => $this->reportTool()((string) $analysis->id, (string) $document->id)),
        );
    }

    public function test_report_refuses_a_proposal_that_is_not_an_object(): void
    {
        $project = $this->scenarioProject('analysis-report-shape');
        $analysis = $this->seedAnalysis($this->em(), $project, AnalysisState::Running);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            'proposals[0] must be an object.',
            $this->refusal(fn () => $this->reportTool()((string) $analysis->id, (string) Uuid::v7(), ['card'])),
        );
        self::assertSame(
            'proposals[0].title must be a string.',
            $this->refusal(fn () => $this->reportTool()((string) $analysis->id, (string) Uuid::v7(), [['kind' => 'card', 'body' => 'Body.']])),
        );
        self::assertSame(
            'proposals[0].payload must be an object.',
            $this->refusal(fn () => $this->reportTool()((string) $analysis->id, (string) Uuid::v7(), [['kind' => 'card', 'title' => 'T', 'body' => 'B', 'payload' => 'x']])),
        );
    }

    private function fact(Project $project, Analysis $analysis, int $cost): void
    {
        $this->em()->getConnection()->insert('bridge_worker_run_facts', [
            'run_id' => (string) Uuid::v7(),
            'project_id' => (string) $project->id,
            'subject_type' => Analysis::SUBJECT_TYPE,
            'subject_id' => (string) $analysis->id,
            'kind' => 'worker',
            'work_kind' => 'analysis',
            'outcome' => 'succeeded',
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 09:05:00',
            'received_at' => '2026-10-07 09:05:00',
            'duration_ms' => 300_000,
            'cost_micro_usd' => $cost,
            'usage_source' => 'reported',
        ]);
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a refusal.');
    }

    private function proposals(): ProposalRepository
    {
        $repository = self::getContainer()->get(ProposalRepository::class);
        self::assertInstanceOf(ProposalRepository::class, $repository);

        return $repository;
    }

    private function getTool(): AnalysisGetTool
    {
        $tool = self::getContainer()->get(AnalysisGetTool::class);
        self::assertInstanceOf(AnalysisGetTool::class, $tool);

        return $tool;
    }

    private function reportTool(): AnalysisReportTool
    {
        $tool = self::getContainer()->get(AnalysisReportTool::class);
        self::assertInstanceOf(AnalysisReportTool::class, $tool);

        return $tool;
    }
}
