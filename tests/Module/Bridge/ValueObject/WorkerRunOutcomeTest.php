<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\ValueObject;

use App\Module\Bridge\ValueObject\WorkerRunOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerRunOutcomeTest extends TestCase
{
    /**
     * @return iterable<string, array{?int, ?bool, WorkerRunOutcome}>
     */
    public static function runs(): iterable
    {
        yield 'no exit code' => [null, null, WorkerRunOutcome::NotStarted];
        yield 'a clean exit with a result' => [0, true, WorkerRunOutcome::Succeeded];
        yield 'a clean exit from an older bridge' => [0, null, WorkerRunOutcome::Succeeded];
        yield 'a clean exit with no result' => [0, false, WorkerRunOutcome::NoResult];
        yield 'a failed exit with no result' => [1, false, WorkerRunOutcome::Failed];
        yield 'a failed exit with a result' => [1, true, WorkerRunOutcome::Failed];
        yield 'a failed exit from an older bridge' => [3, null, WorkerRunOutcome::Failed];
        yield 'a signal' => [-9, false, WorkerRunOutcome::Failed];
    }

    #[DataProvider('runs')]
    public function test_it_reads_the_outcome_off_the_run(?int $exitCode, ?bool $hasResult, WorkerRunOutcome $expected): void
    {
        self::assertSame($expected, WorkerRunOutcome::fromRun($exitCode, $hasResult));
    }

    public function test_no_result_reads_as_a_failure_on_the_page(): void
    {
        self::assertSame('no-result', WorkerRunOutcome::NoResult->value);
        self::assertSame('bridge.worker_runs.outcome.no_result', WorkerRunOutcome::NoResult->translationKey());
        self::assertSame('failed', WorkerRunOutcome::NoResult->chipModifier());
    }
}
