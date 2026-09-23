<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportBridgeRunsCommand;
use App\Module\Bridge\Command\ReportBridgeRunsHandler;
use App\Module\Bridge\Command\ReportWorkerRunCommand;
use App\Module\Bridge\Command\ReportWorkerRunHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateResult;
use App\Module\Bridge\Scheduler\TimeOutQuietWorkerRunsTask;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\HeldRunKey;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\FeatureFlags;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Uid\Uuid;

final class WorkerRunChangedPublisherTest extends KernelTestCase
{
    use BridgeScenario;

    private User $owner;
    private Project $project;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();

        // Before anything builds the hub: the container hands the replacement
        // only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', $this->recordingHub());
        self::getContainer()->set('clock', new MockClock('2026-09-23 12:00:00'));

        $this->owner = $this->user($this->em(), 'run-publish@example.com');
        $this->project = $this->project($this->em(), $this->owner, 'Run publish');
    }

    public function test_a_state_report_signals_the_project_at_terminate(): void
    {
        $this->reportState(Uuid::v4(), WorkerRunState::Queued);

        self::assertCount(0, $this->published);
        $this->assertPublishedAtTerminate(1);
        $this->assertSignalsTheProject($this->published[0]);
    }

    public function test_a_repeated_state_report_changes_nothing_and_publishes_nothing(): void
    {
        $runKey = Uuid::v4();
        $this->reportState($runKey, WorkerRunState::Queued);
        $this->assertPublishedAtTerminate(1);

        $result = $this->reportState($runKey, WorkerRunState::Queued);

        // Guard: the report reached the run, so only the publish stayed away.
        self::assertNotNull($result->run);
        $this->assertPublishedAtTerminate(1);
    }

    public function test_a_report_for_a_project_the_owner_does_not_hold_publishes_nothing(): void
    {
        $stranger = $this->user($this->em(), 'run-publish-stranger@example.com');

        $result = $this->reportState(Uuid::v4(), WorkerRunState::Queued, $stranger);

        self::assertNull($result->run);
        $this->assertPublishedAtTerminate(0);
    }

    public function test_a_finished_run_report_publishes_once(): void
    {
        $command = new ReportWorkerRunCommand(
            owner: $this->owner,
            handle: (string) $this->project->id,
            bridgeId: Uuid::v7(),
            sessionId: Uuid::v4(),
            cardId: Uuid::v7(),
            cardNumber: 3,
            ruleName: 'plan',
            startedAt: new \DateTimeImmutable('2026-09-23 10:00:00'),
            endedAt: new \DateTimeImmutable('2026-09-23 10:01:00'),
            exitCode: 0,
            hasResult: true,
            failureReason: null,
            output: '',
        );
        $handler = $this->service(ReportWorkerRunHandler::class);

        $handler($command);
        $this->assertPublishedAtTerminate(1);
        // A retry of the same report writes nothing.
        self::assertFalse($handler($command)->created);
        $this->assertPublishedAtTerminate(1);
    }

    public function test_an_inventory_publishes_only_when_it_moves_a_run(): void
    {
        $bridgeId = Uuid::v7();
        $runKey = Uuid::v4();
        $this->seedRun($this->em(), $this->project, bridgeId: $bridgeId, state: WorkerRunState::Running, runKey: $runKey);
        $inventory = $this->service(ReportBridgeRunsHandler::class);

        self::assertSame([], $inventory(new ReportBridgeRunsCommand($this->owner, $bridgeId, [HeldRunKey::of($this->project->id ?? Uuid::v4(), $runKey) => WorkerRunState::Running])));
        $this->assertPublishedAtTerminate(0);

        self::assertCount(1, $inventory(new ReportBridgeRunsCommand($this->owner, $bridgeId, [])));
        $this->assertPublishedAtTerminate(1);
        $this->assertSignalsTheProject($this->published[0]);
    }

    /**
     * The sweep runs in the messenger worker, which resets services after each
     * message and ends only at its time limit. The publish must not wait for either.
     */
    public function test_the_sweep_publishes_when_the_worker_has_handled_the_message(): void
    {
        $other = $this->project($this->em(), $this->owner, 'Run publish other');
        foreach ([$this->project, $this->project, $other] as $project) {
            $this->seedRun($this->em(), $project, new \DateTimeImmutable('2026-09-23 11:00:00'), bridgeId: Uuid::v7(), state: WorkerRunState::Running, runKey: Uuid::v4());
        }

        $this->service(TimeOutQuietWorkerRunsTask::class)();
        self::assertCount(0, $this->published);

        $this->dispatcher()->dispatch(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'scheduler_default'));

        // One signal per project, however many of its runs timed out.
        self::assertCount(2, $this->published);
        $topics = array_map(static fn (Update $update): array => $update->getTopics(), $this->published);
        self::assertEqualsCanonicalizing([[$this->runTopic($this->project)], [$this->runTopic($other)]], $topics);
    }

    public function test_with_live_updates_off_it_neither_builds_the_hub_nor_publishes(): void
    {
        $log = new TestHandler();
        $hubBuilt = false;
        $publisher = new WorkerRunChangedPublisher(
            $this->service(ProjectTopicBuilder::class),
            FeatureFlags::service([LiveUpdates::FLAG => false]),
            new Logger('test', [$log]),
            function () use (&$hubBuilt): HubInterface {
                $hubBuilt = true;

                return $this->recordingHub();
            },
        );

        $publisher->runsChanged($this->project);
        $publisher->publish();

        self::assertFalse($hubBuilt);
        self::assertCount(0, $this->published);
        self::assertSame([], $log->getRecords());
    }

    public function test_a_hub_that_fails_is_logged_and_the_rest_still_publish(): void
    {
        $log = new TestHandler();
        $other = $this->project($this->em(), $this->owner, 'Run publish failing');
        $publisher = new WorkerRunChangedPublisher(
            $this->service(ProjectTopicBuilder::class),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new Logger('test', [$log]),
            fn (): HubInterface => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                function (Update $update): string {
                    if ([$this->runTopic($this->project)] === $update->getTopics()) {
                        throw new \RuntimeException('hub unreachable');
                    }
                    $this->published[] = $update;

                    return 'id';
                },
            ),
        );

        $publisher->runsChanged($this->project);
        $publisher->runsChanged($other);
        $publisher->publish();

        self::assertCount(1, $this->published);
        self::assertSame([$this->runTopic($other)], $this->published[0]->getTopics());
        self::assertTrue($log->hasWarning([
            'message' => 'bridge.worker_run_publish_failed',
            'context' => ['projectId' => (string) $this->project->id, 'error' => 'hub unreachable'],
        ]));
    }

    private function reportState(Uuid $runKey, WorkerRunState $state, ?User $owner = null): ReportWorkerRunStateResult
    {
        return $this->service(ReportWorkerRunStateHandler::class)(new ReportWorkerRunStateCommand(
            owner: $owner ?? $this->owner,
            handle: (string) $this->project->id,
            runKey: $runKey,
            bridgeId: Uuid::fromString('0199a0e2-b1f3-7a44-9c11-2d3e4f506180'),
            state: $state,
            at: new \DateTimeImmutable('2026-09-23 10:00:00'),
            cardId: Uuid::v7(),
            cardNumber: 1,
            ruleName: 'plan',
        ));
    }

    /** Ends the request the way the kernel does, then counts every publish so far. */
    private function assertPublishedAtTerminate(int $expected): void
    {
        self::assertNotNull(self::$kernel);
        $this->dispatcher()->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        self::assertCount($expected, $this->published);
    }

    private function assertSignalsTheProject(Update $update): void
    {
        self::assertSame([$this->runTopic($this->project)], $update->getTopics());
        self::assertTrue($update->isPrivate());
        // No run data: what a page shows depends on who looks at it.
        self::assertSame('{"type":"worker_run.changed"}', $update->getData());
    }

    private function runTopic(Project $project): string
    {
        return $this->service(ProjectTopicBuilder::class)->forWorkerRuns($project->id ?? throw new \LogicException('The project has no id.'));
    }

    private function recordingHub(): HubInterface
    {
        return new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        );
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
