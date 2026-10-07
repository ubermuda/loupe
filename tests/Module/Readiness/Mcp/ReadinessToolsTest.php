<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Mcp;

use App\Module\Board\Entity\CardReporter;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\EventListener\FailDiscoveryOnRequestWithdrawn;
use App\Module\Readiness\Mcp\DiscoveryStartTool;
use App\Module\Readiness\Mcp\ReadinessGetTool;
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

    private function liveProject(string $name): Project
    {
        $project = $this->workflowProject($name);
        $em = $this->em();
        $em->persist(new Bridge(AgentCredential::managed($em, $project->owner, $project->owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable()));
        $em->flush();

        return $project;
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
}
