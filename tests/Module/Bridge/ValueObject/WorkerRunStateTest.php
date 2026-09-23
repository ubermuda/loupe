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
            ['queued', 'replaced', 'resumed', 'skipped', 'running', 'waiting-for-person', 'dropped', 'succeeded', 'failed', 'not-started', 'timed-out', 'lost'],
            array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::cases()),
        );
    }

    public function test_the_exit_code_gives_the_outcome(): void
    {
        self::assertSame(WorkerRunState::NotStarted, WorkerRunState::fromExitCode(null));
        self::assertSame(WorkerRunState::Succeeded, WorkerRunState::fromExitCode(0));
        self::assertSame(WorkerRunState::Failed, WorkerRunState::fromExitCode(2));
    }

    public function test_only_queued_resumed_and_running_are_open(): void
    {
        self::assertSame(
            [WorkerRunState::Queued, WorkerRunState::Resumed, WorkerRunState::Running],
            WorkerRunState::openStates(),
        );

        foreach (WorkerRunState::cases() as $state) {
            self::assertSame(\in_array($state, WorkerRunState::openStates(), true), $state->isOpen(), $state->value);
        }
    }

    public function test_the_rank_puts_queued_then_resumed_then_running_then_every_closed_state(): void
    {
        self::assertLessThan(WorkerRunState::Resumed->rank(), WorkerRunState::Queued->rank());
        self::assertLessThan(WorkerRunState::Running->rank(), WorkerRunState::Resumed->rank());

        foreach (WorkerRunState::cases() as $state) {
            if (!$state->isOpen()) {
                self::assertLessThan($state->rank(), WorkerRunState::Running->rank(), $state->value);
                self::assertSame(WorkerRunState::Succeeded->rank(), $state->rank(), $state->value);
            }
        }
    }

    #[DataProvider('states')]
    public function test_every_state_has_a_label_and_a_chip(WorkerRunState $state): void
    {
        self::assertSame('bridge.worker_runs.state.'.str_replace('-', '_', $state->value), $state->translationKey());
        self::assertContains($state->chipModifier(), ['ok', 'failed', 'pending']);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function states(): iterable
    {
        foreach (WorkerRunState::cases() as $state) {
            yield $state->value => [$state];
        }
    }
}
