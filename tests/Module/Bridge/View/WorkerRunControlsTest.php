<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\WorkerRunAction;
use App\Module\Bridge\View\WorkerRunControl;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class WorkerRunControlsTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-29T12:00:00+00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    public function test_a_running_run_offers_stop(): void
    {
        [$project, $bridge] = $this->scenario('controls-stop');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Running);

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Stop, $control?->action);
        self::assertTrue($control->enabled);
        self::assertNull($control->label);
    }

    public function test_a_resumable_run_with_a_session_offers_resume(): void
    {
        [$project, $bridge] = $this->scenario('controls-resume');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Blocked);

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Resume, $control?->action);
        self::assertTrue($control->enabled);
    }

    public function test_a_resumable_run_with_no_session_has_no_control(): void
    {
        [$project, $bridge] = $this->scenario('controls-no-session');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Blocked);
        $run->sessionId = null;
        $this->em()->flush();

        self::assertNull($this->controlOf($project, $run));
    }

    public function test_a_finished_run_has_no_control(): void
    {
        [$project, $bridge] = $this->scenario('controls-succeeded');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Succeeded);

        self::assertNull($this->controlOf($project, $run));
    }

    public function test_an_interactive_run_has_no_control(): void
    {
        [$project, $bridge] = $this->scenario('controls-interactive');
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);

        self::assertNull($this->controlOf($project, $run));
    }

    public function test_a_run_with_no_bridge_has_no_control(): void
    {
        [$project] = $this->scenario('controls-no-bridge');
        $worker = new WorkerRun($project, null, Uuid::v7(), 1, 'plan', WorkerRunState::Running, sessionId: Uuid::v4());
        $this->em()->persist($worker);
        $this->em()->flush();

        self::assertNull($this->controlOf($project, $worker));
    }

    /** @return iterable<string, array{BridgeCommandKind, WorkerRunState, string}> */
    public static function pendingCommands(): iterable
    {
        yield 'stop' => [BridgeCommandKind::StopRun, WorkerRunState::Running, 'bridge.worker_runs.control.stop_requested'];
        yield 'resume' => [BridgeCommandKind::ResumeRun, WorkerRunState::Blocked, 'bridge.worker_runs.control.resume_requested'];
    }

    #[DataProvider('pendingCommands')]
    public function test_a_pending_command_offers_cancel(BridgeCommandKind $kind, WorkerRunState $state, string $label): void
    {
        [$project, $bridge] = $this->scenario('controls-pending-'.$kind->value);
        $run = $this->seedWorkerRun($project, $bridge, $state);
        $this->seedCommand($this->em(), $run, kind: $kind);

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Cancel, $control?->action);
        self::assertTrue($control->enabled);
        self::assertSame($label, $control->label);
        self::assertFalse($control->labelWarns);
    }

    #[DataProvider('pendingCommands')]
    public function test_a_pending_command_on_a_quiet_bridge_says_the_bridge_is_offline(BridgeCommandKind $kind, WorkerRunState $state, string $label): void
    {
        [$project, $bridge] = $this->scenario('controls-offline-'.$kind->value, lastSeenAt: new \DateTimeImmutable('2026-09-28T12:00:00+00:00'));
        $run = $this->seedWorkerRun($project, $bridge, $state);
        $this->seedCommand($this->em(), $run, kind: $kind);

        self::assertSame($label.'_offline', $this->controlOf($project, $run)?->label);
    }

    public function test_a_bridge_that_takes_no_commands_disables_the_action(): void
    {
        [$project, $bridge] = $this->scenario('controls-outdated', capabilities: null);
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Running);

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Stop, $control?->action);
        self::assertFalse($control->enabled);
        self::assertSame('bridge.worker_runs.control.bridge_outdated', $control->disabledReason);
        self::assertSame(['%version%' => WorkerRunControls::COMMANDS_SINCE_VERSION], $control->disabledParameters);
    }

    public function test_a_bridge_the_owner_does_not_hold_offers_no_action(): void
    {
        [$project] = $this->scenario('controls-unknown-bridge');
        $run = $this->seedRun($this->em(), $project, bridgeId: Uuid::v4(), state: WorkerRunState::Running);

        self::assertNull($this->controlOf($project, $run));
    }

    public function test_a_pending_command_on_a_bridge_the_owner_does_not_hold_still_offers_cancel(): void
    {
        [$project] = $this->scenario('controls-unknown-pending');
        $run = $this->seedRun($this->em(), $project, bridgeId: Uuid::v4(), state: WorkerRunState::Running);
        $this->seedCommand($this->em(), $run);

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Cancel, $control?->action);
        self::assertTrue($control->enabled);
    }

    public function test_a_resume_in_the_column_of_the_run_is_enabled(): void
    {
        [$project, $bridge] = $this->scenario('controls-same-column');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Blocked, cardId: $this->card($project, 'implementation'));
        $run->cardColumn = 'implementation';
        $this->em()->flush();

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Resume, $control?->action);
        self::assertTrue($control->enabled);
    }

    /** @return iterable<string, array{?string}> */
    public static function otherColumns(): iterable
    {
        yield 'another column' => ['review'];
        yield 'card gone' => [null];
    }

    #[DataProvider('otherColumns')]
    public function test_a_resume_after_the_card_left_the_column_is_disabled(?string $column): void
    {
        [$project, $bridge] = $this->scenario('controls-card-left-'.($column ?? 'none'));
        $cardId = null === $column ? Uuid::v7() : $this->card($project, $column);
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Blocked, cardId: $cardId);
        $run->cardColumn = 'implementation';
        $this->em()->flush();

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Resume, $control?->action);
        self::assertFalse($control->enabled);
        self::assertSame('bridge.worker_runs.control.card_left', $control->disabledReason);
        self::assertSame(['%column%' => 'implementation'], $control->disabledParameters);
    }

    public function test_a_queued_run_on_a_paused_bridge_says_it_waits(): void
    {
        [$project, $bridge] = $this->scenario('controls-paused');
        $bridge->pausedReported = true;
        $this->em()->flush();
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Queued);

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Stop, $control?->action);
        self::assertSame('bridge.worker_runs.control.waiting_paused', $control->label);
        self::assertFalse($control->labelWarns);
    }

    public function test_a_running_run_on_a_paused_bridge_does_not_say_it_waits(): void
    {
        [$project, $bridge] = $this->scenario('controls-paused-running');
        $bridge->pausedReported = true;
        $this->em()->flush();
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Running);

        self::assertNull($this->controlOf($project, $run)?->label);
    }

    /** @return iterable<string, array{BridgeCommandKind, WorkerRunState, string}> */
    public static function expiredCommands(): iterable
    {
        yield 'stop' => [BridgeCommandKind::StopRun, WorkerRunState::Running, 'bridge.worker_runs.control.stop_expired'];
        yield 'resume' => [BridgeCommandKind::ResumeRun, WorkerRunState::Blocked, 'bridge.worker_runs.control.resume_expired'];
    }

    #[DataProvider('expiredCommands')]
    public function test_an_expired_command_warns_while_the_run_still_needs_it(BridgeCommandKind $kind, WorkerRunState $state, string $label): void
    {
        [$project, $bridge] = $this->scenario('controls-expired-'.$kind->value);
        $run = $this->seedWorkerRun($project, $bridge, $state);
        $this->seedCommand($this->em(), $run, state: BridgeCommandState::Expired, kind: $kind);

        $control = $this->controlOf($project, $run);

        self::assertSame($label, $control?->label);
        self::assertTrue($control->labelWarns);
        self::assertTrue($control->enabled);
    }

    public function test_an_expired_stop_says_nothing_once_the_run_stopped(): void
    {
        [$project, $bridge] = $this->scenario('controls-expired-moot');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Stopped);
        $this->seedCommand($this->em(), $run, state: BridgeCommandState::Expired, kind: BridgeCommandKind::StopRun);

        $control = $this->controlOf($project, $run);

        self::assertSame(WorkerRunAction::Resume, $control?->action);
        self::assertNull($control->label);
    }

    public function test_a_refused_command_shows_the_bridge_reason(): void
    {
        [$project, $bridge] = $this->scenario('controls-refused');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Running);
        $command = $this->seedCommand($this->em(), $run, state: BridgeCommandState::Refused);
        $command->reason = 'The worker already ended.';
        $this->em()->flush();

        $control = $this->controlOf($project, $run);

        self::assertSame('bridge.worker_runs.control.refused', $control?->label);
        self::assertSame(['%reason%' => 'The worker already ended.'], $control->labelParameters);
        self::assertTrue($control->labelWarns);
    }

    public function test_a_refused_command_with_no_reason_says_so(): void
    {
        [$project, $bridge] = $this->scenario('controls-refused-bare');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Running);
        $this->seedCommand($this->em(), $run, state: BridgeCommandState::Refused);

        $control = $this->controlOf($project, $run);

        self::assertSame('bridge.worker_runs.control.refused_no_reason', $control?->label);
        self::assertSame([], $control->labelParameters);
    }

    public function test_only_the_latest_command_counts(): void
    {
        [$project, $bridge] = $this->scenario('controls-latest');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Running);
        $this->seedCommand($this->em(), $run, state: BridgeCommandState::Refused, requestedAt: new \DateTimeImmutable('2026-09-29 10:00:00'));
        $this->seedCommand($this->em(), $run, state: BridgeCommandState::Done, requestedAt: new \DateTimeImmutable('2026-09-29 11:00:00'));

        self::assertNull($this->controlOf($project, $run)?->label);
    }

    public function test_the_higher_id_wins_a_tie_on_the_request_time(): void
    {
        [$project, $bridge] = $this->scenario('controls-tie');
        $run = $this->seedWorkerRun($project, $bridge, WorkerRunState::Running);
        $at = new \DateTimeImmutable('2026-09-29 10:00:00');
        $refused = $this->seedCommand($this->em(), $run, state: BridgeCommandState::Refused, requestedAt: $at);
        $expired = $this->seedCommand($this->em(), $run, state: BridgeCommandState::Expired, requestedAt: $at);
        $expected = strcmp((string) $refused->id, (string) $expired->id) > 0
            ? 'bridge.worker_runs.control.refused_no_reason'
            : 'bridge.worker_runs.control.stop_expired';

        self::assertSame($expected, $this->controlOf($project, $run)?->label);
    }

    public function test_the_query_count_does_not_grow_with_the_rows(): void
    {
        [$project, $bridge] = $this->scenario('controls-queries');
        $other = $this->commandBridge($project->owner, lastSeenAt: new \DateTimeImmutable(self::NOW));
        $card = $this->card($project, 'implementation');
        $runs = [];
        for ($i = 0; $i < 6; ++$i) {
            $run = $this->seedWorkerRun($project, 0 === $i % 2 ? $bridge : $other, 0 === $i % 3 ? WorkerRunState::Running : WorkerRunState::Blocked, cardId: $card);
            $run->cardColumn = 'implementation';
            $this->seedCommand($this->em(), $run, state: BridgeCommandState::Done, requestedAt: new \DateTimeImmutable('2026-09-29 10:00:00'));
            $this->seedCommand($this->em(), $run, state: BridgeCommandState::Expired, requestedAt: new \DateTimeImmutable('2026-09-29 11:00:00'));
            $runs[] = $run;
        }
        $this->em()->flush();

        // The first call also loads the feature flags, which the next calls read from memory.
        $this->queriesFor($project, \array_slice($runs, 0, 1));
        $forTwo = $this->queriesFor($project, \array_slice($runs, 0, 2));
        $forSix = $this->queriesFor($project, $runs);

        self::assertNotEmpty($forTwo);
        self::assertSame(\count($forTwo), \count($forSix), "The query count grew with the rows:\n".implode("\n", $forSix));
    }

    /**
     * @param list<WorkerRun> $runs
     *
     * @return list<string>
     */
    private function queriesFor(Project $project, array $runs): array
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();

        $controls = $this->controls()->forRuns($project, $runs);
        self::assertCount(\count($runs), $controls);

        $statements = [];
        foreach ($holder->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }

        return $statements;
    }

    private function controlOf(Project $project, WorkerRun $run): ?WorkerRunControl
    {
        return $this->controls()->forRuns($project, [$run])[(string) $run->id] ?? null;
    }

    private function controls(): WorkerRunControls
    {
        $controls = self::getContainer()->get(WorkerRunControls::class);
        self::assertInstanceOf(WorkerRunControls::class, $controls);

        return $controls;
    }

    /**
     * @param list<string>|null $capabilities
     *
     * @return array{Project, Bridge}
     */
    private function scenario(string $name, ?array $capabilities = [Bridge::CAPABILITY_COMMANDS], \DateTimeImmutable $lastSeenAt = new \DateTimeImmutable(self::NOW)): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8));
        $bridge = $this->commandBridge($owner, $lastSeenAt, $capabilities);

        return [$project, $bridge];
    }

    /** @param list<string>|null $capabilities */
    private function commandBridge(User $owner, \DateTimeImmutable $lastSeenAt, ?array $capabilities = [Bridge::CAPABILITY_COMMANDS]): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $owner, lastSeenAt: $lastSeenAt);
        $bridge->capabilities = $capabilities;
        $this->em()->flush();

        return $bridge;
    }

    private function seedWorkerRun(Project $project, Bridge $bridge, WorkerRunState $state, ?Uuid $cardId = null): WorkerRun
    {
        return $this->seedRun($this->em(), $project, bridgeId: $bridge->id, cardId: $cardId, state: $state, runKey: Uuid::v7());
    }

    private function card(Project $project, string $column): Uuid
    {
        $em = $this->em();
        $card = new Card($project, new BoardColumn($project, ucfirst($column), $column, 0), 'Card', '', 7);
        $em->persist($card->column);
        $em->persist($card);
        $em->flush();

        return $card->id ?? throw new \LogicException('A flushed card has an id.');
    }
}
