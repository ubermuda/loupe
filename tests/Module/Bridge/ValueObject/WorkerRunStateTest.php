<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\ValueObject;

use App\Module\Bridge\ValueObject\WorkerRunState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerRunStateTest extends TestCase
{
    public function test_the_old_filter_values_keep_their_meaning(): void
    {
        self::assertSame(WorkerRunState::Succeeded, WorkerRunState::from('succeeded'));
        self::assertSame(WorkerRunState::Failed, WorkerRunState::from('failed'));
        self::assertSame(WorkerRunState::NotStarted, WorkerRunState::from('not-started'));
    }

    public function test_every_state_has_its_backing_value(): void
    {
        self::assertSame(
            ['queued', 'replaced', 'resumed', 'skipped', 'running', 'stopping', 'stopped', 'waiting-for-person', 'dropped', 'succeeded', 'failed', 'not-started', 'no-result', 'unfinished', 'blocked', 'waiting-on-forge', 'gave-up', 'timed-out', 'lost', 'closed'],
            array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::cases()),
        );
    }

    /** @return iterable<string, array{?int, ?bool, ?string, WorkerRunState}> */
    public static function outcomes(): iterable
    {
        yield 'no exit code' => [null, null, null, WorkerRunState::NotStarted];
        yield 'a clean exit with a result' => [0, true, null, WorkerRunState::Succeeded];
        yield 'a clean exit from an older bridge' => [0, null, null, WorkerRunState::Succeeded];
        yield 'a clean exit with no result' => [0, false, null, WorkerRunState::NoResult];
        yield 'a failed exit with no result' => [1, false, null, WorkerRunState::Failed];
        yield 'a failed exit with a result' => [1, true, null, WorkerRunState::Failed];
        yield 'a failed exit from an older bridge' => [2, null, null, WorkerRunState::Failed];
        yield 'a signal' => [-9, false, null, WorkerRunState::Failed];
        yield 'a finished worker' => [0, true, 'finished', WorkerRunState::Succeeded];
        yield 'a blocked worker' => [0, true, 'blocked', WorkerRunState::Blocked];
        yield 'an unfinished worker' => [0, true, 'unfinished', WorkerRunState::Unfinished];
        yield 'a worker that waits on the forge' => [0, true, 'waiting', WorkerRunState::WaitingOnForge];
        yield 'a failed exit wins over the status' => [1, true, 'finished', WorkerRunState::Failed];
    }

    #[DataProvider('outcomes')]
    public function test_the_exit_code_the_result_flag_and_the_status_give_the_outcome(?int $exitCode, ?bool $hasResult, ?string $resultStatus, WorkerRunState $expected): void
    {
        self::assertSame($expected, WorkerRunState::fromOutcome($exitCode, $hasResult, $resultStatus));
    }

    public function test_an_exit_code_alone_reads_as_an_older_bridge(): void
    {
        self::assertSame(WorkerRunState::Succeeded, WorkerRunState::fromOutcome(0));
    }

    public function test_the_new_outcomes_reuse_the_chip_palette(): void
    {
        self::assertSame('pending', WorkerRunState::Unfinished->chipModifier());
        self::assertSame('pending', WorkerRunState::Blocked->chipModifier());
        self::assertSame('failed', WorkerRunState::GaveUp->chipModifier());
    }

    public function test_a_run_that_waits_on_the_forge_reads_as_pending(): void
    {
        self::assertSame('bridge.worker_runs.state.waiting_on_forge', WorkerRunState::WaitingOnForge->translationKey());
        self::assertSame('pending', WorkerRunState::WaitingOnForge->chipModifier());
    }

    public function test_no_result_reads_as_a_failure_on_the_page(): void
    {
        self::assertSame('no-result', WorkerRunState::NoResult->value);
        self::assertSame('bridge.worker_runs.state.no_result', WorkerRunState::NoResult->translationKey());
        self::assertSame('failed', WorkerRunState::NoResult->chipModifier());
    }

    public function test_only_the_exit_code_states_are_outcomes(): void
    {
        self::assertSame(
            ['succeeded', 'failed', 'not-started', 'no-result', 'unfinished', 'blocked', 'waiting-on-forge', 'gave-up'],
            array_values(array_map(
                static fn (WorkerRunState $state): string => $state->value,
                array_filter(WorkerRunState::cases(), static fn (WorkerRunState $state): bool => $state->isOutcome()),
            )),
        );
    }

    public function test_only_blocked_and_gave_up_warn_on_the_card(): void
    {
        self::assertSame(
            ['blocked', 'gave-up'],
            array_values(array_map(
                static fn (WorkerRunState $state): string => $state->value,
                array_filter(WorkerRunState::cases(), static fn (WorkerRunState $state): bool => $state->isWarning()),
            )),
        );
    }

    public function test_only_timed_out_and_lost_are_inferred(): void
    {
        self::assertSame(
            ['timed-out', 'lost'],
            array_values(array_map(
                static fn (WorkerRunState $state): string => $state->value,
                array_filter(WorkerRunState::cases(), static fn (WorkerRunState $state): bool => $state->isInferred()),
            )),
        );
    }

    public function test_only_queued_resumed_running_and_stopping_are_open(): void
    {
        self::assertSame(
            [WorkerRunState::Queued, WorkerRunState::Resumed, WorkerRunState::Running, WorkerRunState::Stopping],
            WorkerRunState::openStates(),
        );

        foreach (WorkerRunState::cases() as $state) {
            self::assertSame(\in_array($state, WorkerRunState::openStates(), true), $state->isOpen(), $state->value);
        }
    }

    public function test_the_rank_puts_queued_then_resumed_then_running_then_stopping_then_every_closed_state(): void
    {
        self::assertLessThan(WorkerRunState::Resumed->rank(), WorkerRunState::Queued->rank());
        self::assertLessThan(WorkerRunState::Running->rank(), WorkerRunState::Resumed->rank());
        self::assertLessThan(WorkerRunState::Stopping->rank(), WorkerRunState::Running->rank());

        foreach (WorkerRunState::cases() as $state) {
            if (!$state->isOpen()) {
                self::assertLessThan($state->rank(), WorkerRunState::Stopping->rank(), $state->value);
                self::assertSame(WorkerRunState::Succeeded->rank(), $state->rank(), $state->value);
            }
        }
    }

    #[DataProvider('states')]
    public function test_every_state_has_a_label_and_a_chip(WorkerRunState $state): void
    {
        self::assertSame('bridge.worker_runs.state.'.str_replace('-', '_', $state->value), $state->translationKey());
        self::assertContains($state->chipModifier(), ['ok', 'failed', 'pending', 'resolved']);
    }

    /** An open run is amber, and a run that the bridge set aside by design is grey rather than amber. */
    public function test_the_chip_separates_open_runs_from_runs_set_aside(): void
    {
        foreach (WorkerRunState::openStates() as $state) {
            self::assertSame('pending', $state->chipModifier(), $state->value);
        }

        self::assertSame('resolved', WorkerRunState::Replaced->chipModifier());
        self::assertSame('resolved', WorkerRunState::Skipped->chipModifier());
        self::assertSame('pending', WorkerRunState::WaitingForPerson->chipModifier());
        self::assertSame('ok', WorkerRunState::Succeeded->chipModifier());
        self::assertSame('failed', WorkerRunState::Lost->chipModifier());
        self::assertSame('ok', WorkerRunState::Closed->chipModifier());
    }

    /** A person stopped the run, so no process outcome lies behind it and the bridge never resumes it. */
    public function test_stopped_closes_the_run_and_is_neither_an_outcome_nor_a_warning(): void
    {
        self::assertFalse(WorkerRunState::Stopped->isOpen());
        self::assertFalse(WorkerRunState::Stopped->isOutcome());
        self::assertFalse(WorkerRunState::Stopped->isWarning());
        self::assertFalse(WorkerRunState::Stopped->isInferred());
        self::assertSame('resolved', WorkerRunState::Stopped->chipModifier());
        self::assertSame('pending', WorkerRunState::Stopping->chipModifier());
    }

    /** A person closes an interactive run. No process exit or server guess lies behind it. */
    public function test_closed_is_neither_open_nor_an_outcome_nor_inferred(): void
    {
        self::assertFalse(WorkerRunState::Closed->isOpen());
        self::assertFalse(WorkerRunState::Closed->isOutcome());
        self::assertFalse(WorkerRunState::Closed->isInferred());
    }

    public function test_a_queued_resumed_or_running_run_can_stop(): void
    {
        self::assertSame([WorkerRunState::Queued, WorkerRunState::Resumed, WorkerRunState::Running], WorkerRunState::stoppableStates());
        foreach (WorkerRunState::cases() as $state) {
            self::assertSame(\in_array($state, WorkerRunState::stoppableStates(), true), $state->isStoppable(), $state->value);
        }
        self::assertFalse(WorkerRunState::Stopping->isStoppable());
    }

    public function test_an_ended_run_that_did_not_finish_can_resume(): void
    {
        self::assertSame([
            WorkerRunState::Blocked,
            WorkerRunState::GaveUp,
            WorkerRunState::Failed,
            WorkerRunState::NoResult,
            WorkerRunState::Unfinished,
            WorkerRunState::TimedOut,
            WorkerRunState::Lost,
            WorkerRunState::Stopped,
            WorkerRunState::WaitingForPerson,
        ], WorkerRunState::resumableStates());
        foreach (WorkerRunState::cases() as $state) {
            self::assertSame(\in_array($state, WorkerRunState::resumableStates(), true), $state->isResumable(), $state->value);
        }
        self::assertFalse(WorkerRunState::Succeeded->isResumable());
        self::assertFalse(WorkerRunState::Running->isResumable());
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function states(): iterable
    {
        foreach (WorkerRunState::cases() as $state) {
            yield $state->value => [$state];
        }
    }
}
