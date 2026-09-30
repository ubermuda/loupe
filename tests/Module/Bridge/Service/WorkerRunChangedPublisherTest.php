<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Exception\DomainErrors;
use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\AcknowledgeBridgeCommandCommand;
use App\Module\Bridge\Command\AcknowledgeBridgeCommandHandler;
use App\Module\Bridge\Command\CancelBridgeCommandCommand;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Command\ExpireBridgeCommandsCommand;
use App\Module\Bridge\Command\ExpireBridgeCommandsHandler;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Command\ReportBridgeRunsCommand;
use App\Module\Bridge\Command\ReportBridgeRunsHandler;
use App\Module\Bridge\Command\ReportSessionUsageCommand;
use App\Module\Bridge\Command\ReportSessionUsageHandler;
use App\Module\Bridge\Command\ReportWorkerRunCommand;
use App\Module\Bridge\Command\ReportWorkerRunHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateResult;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Command\SetBridgePauseCommand;
use App\Module\Bridge\Command\SetBridgePauseHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Scheduler\TimeOutQuietWorkerRunsTask;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\HeldRunKey;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\FeatureFlags;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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

    public function test_a_card_warning_change_signals_the_board_with_the_card(): void
    {
        $cardId = Uuid::v7();

        $this->service(WorkerRunChangedPublisher::class)->cardWarningChanged($this->project, $cardId);

        $this->assertPublishedAtTerminate(1);
        self::assertSame([$this->boardTopic()], $this->published[0]->getTopics());
        self::assertTrue($this->published[0]->isPrivate());
        self::assertSame('{"type":"worker_run.card_warning_changed","cardId":"'.$cardId.'","origin":null}', $this->published[0]->getData());
    }

    public function test_a_state_report_that_gives_up_signals_the_card_once(): void
    {
        $cardId = Uuid::v7();
        $runKey = Uuid::v4();
        $this->reportState($runKey, WorkerRunState::Running, cardId: $cardId);
        $this->assertPublishedAtTerminate(1);

        $this->reportState($runKey, WorkerRunState::GaveUp, cardId: $cardId);
        $this->assertPublishedAtTerminate(3);
        self::assertSame([(string) $cardId], $this->warnedCards());

        $result = $this->reportState($runKey, WorkerRunState::GaveUp, cardId: $cardId);
        self::assertNotNull($result->run);
        $this->assertPublishedAtTerminate(3);
    }

    public function test_a_blocked_state_report_signals_the_card(): void
    {
        $cardId = Uuid::v7();

        $this->reportState(Uuid::v4(), WorkerRunState::Blocked, cardId: $cardId);

        $this->assertPublishedAtTerminate(2);
        self::assertSame([(string) $cardId], $this->warnedCards());
    }

    public function test_a_success_with_no_warning_before_signals_no_card(): void
    {
        $this->reportState(Uuid::v4(), WorkerRunState::Succeeded);

        // Guard: the run pages heard of the report.
        $this->assertPublishedAtTerminate(1);
        self::assertSame([], $this->warnedCards());
    }

    public function test_a_success_after_a_warning_signals_the_card(): void
    {
        $cardId = Uuid::v7();
        $this->seedWarning($cardId, WorkerRunState::GaveUp);

        $this->reportState(Uuid::v4(), WorkerRunState::Succeeded, cardId: $cardId);

        $this->assertPublishedAtTerminate(2);
        self::assertSame([(string) $cardId], $this->warnedCards());
    }

    public function test_a_finished_run_report_after_a_warning_signals_the_card_once(): void
    {
        $cardId = Uuid::v7();
        $this->seedWarning($cardId, WorkerRunState::GaveUp);
        $command = $this->finishedRun($cardId);
        $handler = $this->service(ReportWorkerRunHandler::class);

        $handler($command);
        $this->assertPublishedAtTerminate(2);
        self::assertSame([(string) $cardId], $this->warnedCards());

        self::assertFalse($handler($command)->created);
        $this->assertPublishedAtTerminate(2);
    }

    public function test_a_finished_run_report_with_no_warning_before_signals_no_card(): void
    {
        $this->service(ReportWorkerRunHandler::class)($this->finishedRun(Uuid::v7()));

        $this->assertPublishedAtTerminate(1);
        self::assertSame([], $this->warnedCards());
    }

    public function test_a_launch_failure_after_a_warning_signals_the_card_once(): void
    {
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $this->seedWarning($cardId, WorkerRunState::Blocked);
        $fail = fn (): array => $this->service(InteractiveRuns::class)->recordLaunchFailure(
            $this->project, $cardId, 3, $sessionId, 'design', Uuid::v7(), 'launcher exited 1', new \DateTimeImmutable('2026-09-23 11:59:00'),
        );

        self::assertTrue($fail()[1]);
        $this->assertPublishedAtTerminate(2);
        self::assertSame([(string) $cardId], $this->warnedCards());

        self::assertFalse($fail()[1]);
        $this->assertPublishedAtTerminate(2);
    }

    public function test_a_launch_failure_with_no_warning_before_signals_no_card(): void
    {
        [, $created] = $this->service(InteractiveRuns::class)->recordLaunchFailure(
            $this->project, Uuid::v7(), 3, Uuid::v4(), 'design', Uuid::v7(), 'launcher exited 1', new \DateTimeImmutable('2026-09-23 11:59:00'),
        );

        self::assertTrue($created);
        $this->assertPublishedAtTerminate(1);
        self::assertSame([], $this->warnedCards());
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

    public function test_a_session_usage_report_publishes_only_when_it_changes_a_run(): void
    {
        $sessionId = Uuid::v4();
        $run = $this->seedRun($this->em(), $this->project);
        $run->sessionId = $sessionId;
        $this->em()->flush();
        $handler = $this->service(ReportSessionUsageHandler::class);
        $command = new ReportSessionUsageCommand($this->owner, (string) $this->project->id, $sessionId, [
            new WorkerRunUsageReport(WorkerRunUsageSource::Reported, []),
        ]);

        self::assertSame(1, $handler($command)->updated);
        $this->assertPublishedAtTerminate(1);
        $this->assertSignalsTheProject($this->published[0]);

        self::assertSame(0, $handler($command)->updated);
        $this->assertPublishedAtTerminate(1);
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

    public function test_a_command_request_publishes_and_a_refusal_does_not(): void
    {
        $run = $this->commandRun();
        $request = $this->service(RequestBridgeCommandHandler::class);

        $request(new RequestBridgeCommandCommand($run, BridgeCommandKind::StopRun, $this->owner));
        $this->assertRunsChangedAtTerminate([$this->project]);

        try {
            $request(new RequestBridgeCommandCommand($run, BridgeCommandKind::StopRun, $this->owner));
            self::fail('Expected a refusal.');
        } catch (DomainErrors) {
        }
        $this->assertRunsChangedAtTerminate([$this->project]);
    }

    public function test_a_cancel_publishes_and_a_refused_cancel_does_not(): void
    {
        $run = $this->commandRun();
        $this->seedCommand($this->em(), $run);
        $cancel = $this->service(CancelBridgeCommandHandler::class);

        $cancel(new CancelBridgeCommandCommand($run, $this->owner));
        $this->assertRunsChangedAtTerminate([$this->project]);

        try {
            $cancel(new CancelBridgeCommandCommand($run, $this->owner));
            self::fail('Expected a refusal.');
        } catch (DomainErrors) {
        }
        $this->assertRunsChangedAtTerminate([$this->project]);
    }

    public function test_an_ack_publishes_only_when_it_settles_the_command(): void
    {
        $command = $this->seedCommand($this->em(), $this->commandRun());
        $ack = new AcknowledgeBridgeCommandCommand($this->owner, $command->bridgeId, $command->id ?? throw new \LogicException('The command has no id.'), BridgeCommandState::Done, null);
        $handler = $this->service(AcknowledgeBridgeCommandHandler::class);

        self::assertTrue($handler($ack)->settled);
        $this->assertRunsChangedAtTerminate([$this->project]);

        self::assertFalse($handler($ack)->settled);
        $this->assertRunsChangedAtTerminate([$this->project]);
    }

    public function test_the_expiry_sweep_publishes_once_per_project_with_an_expired_command(): void
    {
        $other = $this->project($this->em(), $this->owner, 'Run publish expiry');
        $quiet = $this->project($this->em(), $this->owner, 'Run publish quiet');
        $due = new \DateTimeImmutable('2026-09-23 11:00:00');
        foreach ([$this->project, $this->project, $other] as $project) {
            $this->seedCommand($this->em(), $this->seedRun($this->em(), $project), requestedAt: $due->modify('-15 minutes'), expiresAt: $due);
        }
        $this->seedCommand($this->em(), $this->seedRun($this->em(), $quiet), requestedAt: $due, expiresAt: new \DateTimeImmutable('2026-09-23 12:30:00'));

        self::assertSame(3, $this->service(ExpireBridgeCommandsHandler::class)(new ExpireBridgeCommandsCommand()));

        $this->assertRunsChangedAtTerminate([$this->project, $other]);

        self::assertSame(0, $this->service(ExpireBridgeCommandsHandler::class)(new ExpireBridgeCommandsCommand()));
        $this->assertRunsChangedAtTerminate([$this->project, $other]);
    }

    public function test_a_heartbeat_publishes_on_the_owned_projects_when_the_reported_pause_changes(): void
    {
        $stranger = $this->user($this->em(), 'run-publish-heartbeat-stranger@example.com');
        $foreign = $this->project($this->em(), $stranger, 'Run publish foreign');
        $bridge = $this->seedBridge($this->em(), $this->owner, projects: [(string) $this->project->id]);
        $bridge->pausedReported = false;
        $this->em()->flush();
        $heartbeat = fn (?bool $paused) => $this->service(RecordBridgeHeartbeatHandler::class)(new RecordBridgeHeartbeatCommand(
            $this->owner, $bridge->id, [(string) $this->project->id, (string) $foreign->id], 'b4e39aa7', paused: $paused,
        ));

        $heartbeat(true);
        $this->assertRunsChangedAtTerminate([$this->project]);

        $heartbeat(true);
        $heartbeat(null);
        $this->assertRunsChangedAtTerminate([$this->project]);
    }

    public function test_a_pause_change_publishes_on_the_owned_projects_of_the_bridge(): void
    {
        $stranger = $this->user($this->em(), 'run-publish-pause-stranger@example.com');
        $foreign = $this->project($this->em(), $stranger, 'Run publish pause foreign');
        $bridge = $this->seedBridge($this->em(), $this->owner, projects: [(string) $this->project->id, (string) $foreign->id]);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();
        $pause = fn (bool $paused) => $this->service(SetBridgePauseHandler::class)(new SetBridgePauseCommand($this->owner, $bridge->id, $paused, $this->owner));

        $pause(true);
        $this->assertRunsChangedAtTerminate([$this->project]);

        $pause(true);
        $this->assertRunsChangedAtTerminate([$this->project]);
    }

    public function test_with_live_updates_off_it_neither_builds_the_hub_nor_publishes(): void
    {
        $log = new TestHandler();
        $hubBuilt = false;
        $live = new LiveUpdatePublisher(
            new RequestStack(),
            FeatureFlags::service([LiveUpdates::FLAG => false]),
            new Logger('test', [$log]),
            function () use (&$hubBuilt): HubInterface {
                $hubBuilt = true;

                return $this->recordingHub();
            },
        );

        new WorkerRunChangedPublisher($this->service(ProjectTopicBuilder::class), $live)->runsChanged($this->project);
        $live->publish();

        self::assertFalse($hubBuilt);
        self::assertCount(0, $this->published);
        self::assertSame([], $log->getRecords());
    }

    public function test_a_hub_that_fails_is_logged_and_the_rest_still_publish(): void
    {
        $log = new TestHandler();
        $other = $this->project($this->em(), $this->owner, 'Run publish failing');
        $live = new LiveUpdatePublisher(
            new RequestStack(),
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

        $publisher = new WorkerRunChangedPublisher($this->service(ProjectTopicBuilder::class), $live);

        $publisher->runsChanged($this->project);
        $publisher->runsChanged($other);
        $live->publish();

        self::assertCount(1, $this->published);
        self::assertSame([$this->runTopic($other)], $this->published[0]->getTopics());
        self::assertTrue($log->hasWarning([
            'message' => 'live_updates.publish_failed',
            'context' => ['topic' => $this->runTopic($this->project), 'error' => 'hub unreachable'],
        ]));
    }

    private function reportState(Uuid $runKey, WorkerRunState $state, ?User $owner = null, ?Uuid $cardId = null): ReportWorkerRunStateResult
    {
        return $this->service(ReportWorkerRunStateHandler::class)(new ReportWorkerRunStateCommand(
            owner: $owner ?? $this->owner,
            handle: (string) $this->project->id,
            runKey: $runKey,
            bridgeId: Uuid::fromString('0199a0e2-b1f3-7a44-9c11-2d3e4f506180'),
            state: $state,
            at: new \DateTimeImmutable('2026-09-23 10:00:00'),
            cardId: $cardId ?? Uuid::v7(),
            cardNumber: 1,
            ruleName: 'plan',
            endedAt: $state->isOutcome() ? new \DateTimeImmutable('2026-09-23 10:05:00') : null,
        ));
    }

    private function commandRun(): WorkerRun
    {
        $bridge = $this->seedBridge($this->em(), $this->owner);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();

        return $this->seedRun($this->em(), $this->project, bridgeId: $bridge->id, state: WorkerRunState::Running);
    }

    private function finishedRun(Uuid $cardId): ReportWorkerRunCommand
    {
        return new ReportWorkerRunCommand(
            owner: $this->owner,
            handle: (string) $this->project->id,
            bridgeId: Uuid::fromString('0199a0e2-b1f3-7a44-9c11-2d3e4f506180'),
            sessionId: Uuid::v4(),
            cardId: $cardId,
            cardNumber: 3,
            ruleName: 'plan',
            startedAt: new \DateTimeImmutable('2026-09-23 11:30:00'),
            endedAt: new \DateTimeImmutable('2026-09-23 11:31:00'),
            exitCode: 0,
            hasResult: true,
            failureReason: null,
            output: '',
        );
    }

    /** Received before the clock of the test, so a later report is the latest outcome. */
    private function seedWarning(Uuid $cardId, WorkerRunState $state): void
    {
        $this->seedRun($this->em(), $this->project, new \DateTimeImmutable('2026-09-23 11:00:00'), cardId: $cardId, state: $state, hasResult: true);
        self::assertNotNull($this->service(WorkerRunRepository::class)->findWarningRowOfCard($this->project, $cardId));
    }

    /** @return list<string> the card of each board message so far */
    private function warnedCards(): array
    {
        $cards = [];
        foreach ($this->published as $update) {
            if ([$this->boardTopic()] !== $update->getTopics()) {
                continue;
            }
            $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertSame(WorkerRunChangedPublisher::CARD_WARNING_CHANGED, $data['type']);
            self::assertIsString($data['cardId']);
            $cards[] = $data['cardId'];
        }

        return $cards;
    }

    private function boardTopic(): string
    {
        return $this->service(ProjectTopicBuilder::class)->forBoard($this->project->id ?? throw new \LogicException('The project has no id.'));
    }

    /** Ends the request the way the kernel does, then counts every publish so far. */
    private function assertPublishedAtTerminate(int $expected): void
    {
        self::assertNotNull(self::$kernel);
        $this->dispatcher()->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        self::assertCount($expected, $this->published);
    }

    /**
     * Ends the request, then compares the run pages told so far. Other topics,
     * such as the activity feed an outbox write tells, do not count.
     *
     * @param list<Project> $projects
     */
    private function assertRunsChangedAtTerminate(array $projects): void
    {
        self::assertNotNull(self::$kernel);
        $this->dispatcher()->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        $runsChanged = array_values(array_filter(
            $this->published,
            static fn (Update $update): bool => str_contains($update->getData(), '"type":"'.WorkerRunChangedPublisher::TYPE.'"'),
        ));
        foreach ($runsChanged as $update) {
            self::assertTrue($update->isPrivate());
            self::assertSame('{"type":"worker_run.changed","origin":null}', $update->getData());
        }
        self::assertEqualsCanonicalizing(
            array_map($this->runTopic(...), $projects),
            array_map(static fn (Update $update): string => $update->getTopics()[0], $runsChanged),
        );
    }

    private function assertSignalsTheProject(Update $update): void
    {
        self::assertSame([$this->runTopic($this->project)], $update->getTopics());
        self::assertTrue($update->isPrivate());
        // No run data: what a page shows depends on who looks at it.
        self::assertSame('{"type":"worker_run.changed","origin":null}', $update->getData());
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
