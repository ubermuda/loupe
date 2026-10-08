<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Mcp;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\CardReporter;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\EventListener\FailDiscoveryOnRequestWithdrawn;
use App\Module\Readiness\Mcp\DiscoveryStartTool;
use App\Module\Readiness\Mcp\ReadinessGetTool;
use App\Module\Readiness\Mcp\ReadinessReportSubmitTool;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReadinessToolsTest extends KernelTestCase
{
    use DiscoveryScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_readiness_get_reads_the_rows_while_the_guide_is_hidden(): void
    {
        $project = $this->workflowProject('readiness-get-hidden');
        $project->readinessGuideHiddenAt = new \DateTimeImmutable();
        $this->em()->flush();
        $this->actAsMcpTokenBoundTo($project);

        $answer = $this->readinessGet()();

        self::assertTrue($answer['guideHidden']);
        self::assertSame(['agent', 'workflow', 'bridge', 'github', 'agent_account', 'repository'], array_column($answer['rows'], 'key'));
        self::assertSame(['key' => 'repository', 'done' => false, 'status' => 'Discovery waits for a running bridge'], $answer['rows'][5]);
        self::assertNull($answer['discovery']);
    }

    public function test_readiness_get_reports_a_failed_run_with_its_reason(): void
    {
        $project = $this->workflowProject('readiness-get-failed');
        $run = $this->discoveryRun($this->discoveryCard($project));
        $run->fail(FailDiscoveryOnRequestWithdrawn::NO_TAKER, new \DateTimeImmutable());
        $this->em()->flush();
        $this->actAsMcpTokenBoundTo($project);

        $answer = $this->readinessGet()();

        self::assertFalse($answer['guideHidden']);
        self::assertSame('Discovery failed: No bridge took the work.', $answer['rows'][5]['status']);
        self::assertSame([
            'runId' => (string) $run->id,
            'state' => 'failed',
            'cardId' => (string) $run->card->id,
            'cardNumber' => $run->card->number,
            'reason' => FailDiscoveryOnRequestWithdrawn::NO_TAKER,
            'createdAt' => $run->createdAt->format(\DATE_ATOM),
        ], $answer['discovery']);
    }

    public function test_discovery_start_opens_a_run_reported_by_the_agent(): void
    {
        $project = $this->liveProject('discovery-start-tool');
        $this->actAsMcpTokenBoundTo($project);

        $answer = $this->discoveryStart()();

        $run = $this->em()->find(DiscoveryRun::class, $answer['runId']);
        self::assertNotNull($run);
        self::assertSame(['cardId' => (string) $run->card->id, 'cardNumber' => $run->card->number, 'runId' => (string) $run->id, 'state' => 'requested'], $answer);
        self::assertSame(CardReporter::Agent, $run->card->reporter);
    }

    public function test_discovery_start_with_no_live_bridge_names_the_fix(): void
    {
        $this->actAsMcpTokenBoundTo($this->workflowProject('discovery-start-tool-no-bridge'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Start a bridge that serves this project');

        $this->discoveryStart()();
    }

    public function test_discovery_start_with_no_workflow_names_the_fix(): void
    {
        $project = $this->workflowProject('discovery-start-tool-no-workflow');
        $this->addBridge($project);
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Choose a workflow for this project');

        $this->discoveryStart()();
    }

    public function test_discovery_start_with_board_automation_off_names_the_fix(): void
    {
        $project = $this->liveProject('discovery-start-tool-automation-off');
        $this->em()->persist(new BoardAutomationSettings($project, enabled: false));
        $this->em()->flush();
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Switch on "Run the workflow of the board"');

        $this->discoveryStart()();
    }

    public function test_a_second_discovery_start_names_the_open_card(): void
    {
        $project = $this->liveProject('discovery-start-tool-twice');
        $this->actAsMcpTokenBoundTo($project);
        $first = $this->discoveryStart()();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Discovery already runs on card #'.$first['cardNumber']);

        $this->discoveryStart()();
    }

    public function test_an_unbound_token_is_refused(): void
    {
        $project = $this->workflowProject('discovery-start-tool-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);

        $this->discoveryStart()();
    }

    public function test_readiness_report_submit_stores_the_report_and_returns_the_document(): void
    {
        $project = $this->workflowProject('report-submit-tool');
        $run = $this->discoveryRun($this->discoveryCard($project));
        $this->actAsMcpTokenBoundTo($project);

        $answer = $this->reportSubmit()(
            (string) $run->id,
            'lifecycle',
            [['check' => 'Tests run', 'status' => 'gap', 'evidence' => 'None found.']],
            [['key' => 'tests', 'title' => 'Add tests', 'type' => 'feature', 'body' => 'Write them.']],
            'A short summary.',
        );

        $document = $this->em()->find(Document::class, $answer['documentId']);
        self::assertNotNull($document);
        self::assertSame($document, $run->reportDocument);
        self::assertSame(DiscoveryRunState::Reported, $run->state);
        self::assertStringEndsWith('/projects/'.$project->id.'/documents/'.$document->id.'/review', $answer['url']);
    }

    public function test_readiness_report_submit_names_the_fix_for_each_refusal(): void
    {
        $project = $this->workflowProject('report-submit-tool-refusals');
        $run = $this->discoveryRun($this->discoveryCard($project));
        $this->actAsMcpTokenBoundTo($project);
        $finding = ['check' => 'Tests run', 'status' => 'ready', 'evidence' => 'Yes.'];
        $proposal = ['key' => 'a', 'title' => 'A', 'type' => 'feature', 'body' => 'B'];

        $cases = [
            'Call readiness_get or discovery_start for the runId' => [Uuid::v4()->toRfc4122(), [$finding], [$proposal]],
            'A proposal type must be one of feature, bug, security, tooling, docs, idea. Call board_columns' => [(string) $run->id, [$finding], [['type' => 'epic'] + $proposal]],
            'Two proposals have the same key' => [(string) $run->id, [$finding], [$proposal, $proposal]],
            'Each finding needs a check, a status and an evidence text, all as strings' => [(string) $run->id, [['check' => 'x']], []],
            'Each proposal needs a key, a title, a type and a body as strings' => [(string) $run->id, [$finding], [['key' => 'a']]],
            'An openCardNumber names a card that does not exist' => [(string) $run->id, [$finding], [['openCardNumber' => 999] + $proposal]],
        ];
        foreach ($cases as $message => [$runId, $findings, $proposals]) {
            try {
                $this->reportSubmit()($runId, 'lifecycle', $findings, $proposals);
                self::fail('The tool accepted the report that should name: '.$message);
            } catch (ToolCallException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertSame(DiscoveryRunState::Requested, $run->state);
    }

    public function test_readiness_report_submit_refuses_a_second_report_for_the_run(): void
    {
        $project = $this->workflowProject('report-submit-tool-twice');
        $run = $this->discoveryRun($this->discoveryCard($project));
        $this->actAsMcpTokenBoundTo($project);
        $this->reportSubmit()((string) $run->id, 'lifecycle', []);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This run has a report already');

        $this->reportSubmit()((string) $run->id, 'lifecycle', []);
    }

    public function test_readiness_report_submit_refuses_an_unbound_token(): void
    {
        $project = $this->workflowProject('report-submit-tool-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);

        $this->reportSubmit()(Uuid::v4()->toRfc4122(), 'lifecycle', []);
    }

    private function liveProject(string $name): Project
    {
        $project = $this->workflowProject($name);
        $this->bindLifecycle($project);
        $this->addBridge($project);

        return $project;
    }

    private function addBridge(Project $project): void
    {
        $em = $this->em();
        $em->persist(new Bridge(AgentCredential::managed($em, $project->owner, $project->owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable()));
        $em->flush();
    }

    private function readinessGet(): ReadinessGetTool
    {
        $tool = self::getContainer()->get(ReadinessGetTool::class);
        self::assertInstanceOf(ReadinessGetTool::class, $tool);

        return $tool;
    }

    private function discoveryStart(): DiscoveryStartTool
    {
        $tool = self::getContainer()->get(DiscoveryStartTool::class);
        self::assertInstanceOf(DiscoveryStartTool::class, $tool);

        return $tool;
    }

    private function reportSubmit(): ReadinessReportSubmitTool
    {
        $tool = self::getContainer()->get(ReadinessReportSubmitTool::class);
        self::assertInstanceOf(ReadinessReportSubmitTool::class, $tool);

        return $tool;
    }
}
