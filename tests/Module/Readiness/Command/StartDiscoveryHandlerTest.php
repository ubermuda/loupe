<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\CardReporter;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\DiscoveryRunning;
use App\Module\Readiness\Command\StartDiscoveryCommand;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class StartDiscoveryHandlerTest extends KernelTestCase
{
    use DiscoveryScenario;

    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $this->transport()->reset();
    }

    public function test_a_start_creates_a_tooling_card_in_the_backlog_and_a_requested_run(): void
    {
        $project = $this->liveProject();

        $run = $this->handler()(new StartDiscoveryCommand($project, CardReporter::Human));

        self::assertSame(DiscoveryRunState::Requested, $run->state);
        self::assertSame($project, $run->project);
        $card = $run->card;
        self::assertSame('Discover what '.$project->name.' needs for agents', $card->title);
        self::assertSame('tooling', $card->type);
        self::assertTrue($card->column->backlog);
        self::assertSame(CardReporter::Human, $card->reporter);
        self::assertNotSame('', $card->body);
        self::assertSame([(string) $card->id], $this->evaluatedCardIds());
        $started = array_values(array_filter($this->audit->sink->events, static fn ($event): bool => 'readiness.discovery_started' === $event->operation));
        self::assertCount(1, $started);
        self::assertSame((string) $run->id, $started[0]->context['discoveryRunId']);
        self::assertSame((string) $card->id, $started[0]->context['cardId']);
    }

    public function test_an_agent_start_records_the_agent_as_reporter(): void
    {
        $run = $this->handler()(new StartDiscoveryCommand($this->liveProject(), CardReporter::Agent));

        self::assertSame(CardReporter::Agent, $run->card->reporter);
    }

    public function test_the_queued_evaluation_opens_the_discovery_request(): void
    {
        $project = $this->liveProject();

        $run = $this->handler()(new StartDiscoveryCommand($project, CardReporter::Human));
        $engine = self::getContainer()->get(Engine::class);
        self::assertInstanceOf(Engine::class, $engine);
        foreach ($this->evaluatedCardIds() as $cardId) {
            $engine->evaluate(Uuid::fromString($cardId), new \DateTimeImmutable());
        }

        $requests = $this->requests($run);
        self::assertCount(1, $requests);
        self::assertSame(['discovery', WorkRequestState::Open], [$requests[0]->ruleId, $requests[0]->state]);
    }

    public function test_a_project_with_no_live_bridge_is_refused(): void
    {
        $project = $this->workflowProject('discovery-start-no-bridge');
        $this->bridge($project, new \DateTimeImmutable('-1 day'));

        try {
            $this->handler()(new StartDiscoveryCommand($project, CardReporter::Human));
            self::fail('A start with no live bridge must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['bridge' => StartDiscoveryHandler::NO_LIVE_BRIDGE], $e->errors);
        }

        self::assertSame([], $this->runs($project));
        self::assertSame([], $this->evaluatedCardIds());
    }

    public function test_a_project_with_no_workflow_is_refused(): void
    {
        $project = $this->workflowProject('discovery-start-no-workflow');
        $this->bridge($project, new \DateTimeImmutable());

        $this->assertRefused($project, ['workflow' => StartDiscoveryHandler::NO_WORKFLOW]);
    }

    public function test_a_project_whose_board_automation_is_off_is_refused(): void
    {
        $project = $this->liveProject();
        $this->em()->persist(new BoardAutomationSettings($project, enabled: false));
        $this->em()->flush();

        $this->assertRefused($project, ['automation' => StartDiscoveryHandler::AUTOMATION_OFF]);
    }

    public function test_a_second_start_while_a_run_is_requested_names_its_card(): void
    {
        $project = $this->liveProject();
        $first = $this->handler()(new StartDiscoveryCommand($project, CardReporter::Human));

        try {
            $this->handler()(new StartDiscoveryCommand($project, CardReporter::Human));
            self::fail('A second start must be refused while a run is requested.');
        } catch (DiscoveryRunning $e) {
            self::assertSame($first->card->number, $e->cardNumber);
        }

        self::assertCount(1, $this->runs($project));
    }

    public function test_a_start_after_a_failed_run_makes_a_new_card(): void
    {
        $project = $this->liveProject();
        $first = $this->handler()(new StartDiscoveryCommand($project, CardReporter::Human));
        $first->fail('lost', new \DateTimeImmutable());
        $this->em()->flush();

        $second = $this->handler()(new StartDiscoveryCommand($project, CardReporter::Human));

        self::assertNotSame($first->card->id, $second->card->id);
        self::assertSame(DiscoveryRunState::Requested, $second->state);
        self::assertCount(2, $this->runs($project));
    }

    private function liveProject(): Project
    {
        $project = $this->workflowProject('discovery-start');
        $this->bindLifecycle($project);
        $this->bridge($project, new \DateTimeImmutable());

        return $project;
    }

    /** @param array<string, string> $errors */
    private function assertRefused(Project $project, array $errors): void
    {
        try {
            $this->handler()(new StartDiscoveryCommand($project, CardReporter::Human));
            self::fail('The start must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }

        self::assertSame([], $this->runs($project));
        self::assertSame([], $this->evaluatedCardIds());
    }

    private function bridge(Project $project, \DateTimeImmutable $lastSeenAt): void
    {
        $em = $this->em();
        $em->persist(new Bridge(AgentCredential::managed($em, $project->owner, $project->owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', $lastSeenAt));
        $em->flush();
    }

    private function handler(): StartDiscoveryHandler
    {
        $handler = self::getContainer()->get(StartDiscoveryHandler::class);
        self::assertInstanceOf(StartDiscoveryHandler::class, $handler);

        return $handler;
    }

    /** @return list<DiscoveryRun> */
    private function runs(Project $project): array
    {
        return array_values($this->discoveryRuns()->findBy(['project' => $project]));
    }

    /** @return list<WorkRequest> */
    private function requests(DiscoveryRun $run): array
    {
        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);

        return array_values($repository->findBy(['subjectId' => $run->card->id]));
    }

    /** @return list<string> */
    private function evaluatedCardIds(): array
    {
        $ids = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $ids[] = $message->cardId;
            }
        }

        return $ids;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function discoveryRuns(): DiscoveryRunRepository
    {
        $repository = static::getContainer()->get(DiscoveryRunRepository::class);
        self::assertInstanceOf(DiscoveryRunRepository::class, $repository);

        return $repository;
    }
}
