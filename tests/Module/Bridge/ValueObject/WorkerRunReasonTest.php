<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\ValueObject;

use App\Module\Bridge\ValueObject\WorkerRunReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerRunReasonTest extends TestCase
{
    public function test_every_reason_has_its_backing_value(): void
    {
        self::assertSame(
            ['done', 'card-left', 'waiting-checks', 'not-approved', 'approval-stale', 'stacked', 'conflicting', 'not-behind', 'no-design', 'open-pull-request', 'no-pull-request', 'tool-unavailable', 'worktree-failed', 'merge-refused', 'needs-person', 'work-remains', 'other'],
            array_map(static fn (WorkerRunReason $reason): string => $reason->value, WorkerRunReason::cases()),
        );
    }

    /** @return iterable<string, array{?string, ?WorkerRunReason}> */
    public static function reportedCodes(): iterable
    {
        yield 'no code' => [null, null];
        yield 'an empty code' => ['', null];
        yield 'a blank code' => ['  ', null];
        yield 'a known code' => ['stacked', WorkerRunReason::Stacked];
        yield 'a known code with spaces around it' => [' approval-stale ', WorkerRunReason::ApprovalStale];
        yield 'the other code' => ['other', WorkerRunReason::Other];
        yield 'an unknown code' => ['rate-limited', WorkerRunReason::Other];
    }

    #[DataProvider('reportedCodes')]
    public function test_a_reported_code_gives_its_reason(?string $code, ?WorkerRunReason $expected): void
    {
        self::assertSame($expected, WorkerRunReason::fromReported($code));
    }
}
