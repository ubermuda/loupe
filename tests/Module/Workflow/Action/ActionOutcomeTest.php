<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionOutcomeKind;
use App\Module\Workflow\Contract\PauseKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActionOutcomeTest extends TestCase
{
    public function test_done_carries_no_code(): void
    {
        $outcome = ActionOutcome::done();

        self::assertSame(ActionOutcomeKind::Done, $outcome->kind);
        self::assertNull($outcome->code);
        self::assertNull($outcome->pauseKind);
    }

    public function test_a_refusal_carries_its_code(): void
    {
        $outcome = ActionOutcome::refused('workflow-slot-missing');

        self::assertSame(ActionOutcomeKind::Refused, $outcome->kind);
        self::assertSame('workflow-slot-missing', $outcome->code);
        self::assertNull($outcome->pauseKind);
    }

    public function test_a_pause_carries_its_kind_and_reason(): void
    {
        $outcome = ActionOutcome::pause(PauseKind::WorkLimit, 'Work_Limit reached');

        self::assertSame(ActionOutcomeKind::Pause, $outcome->kind);
        self::assertSame('work-limit-reached', $outcome->code);
        self::assertSame(PauseKind::WorkLimit, $outcome->pauseKind);
    }

    #[DataProvider('causes')]
    public function test_a_cause_becomes_a_code(string $cause, string $code): void
    {
        self::assertSame($code, ActionOutcome::code($cause));
        self::assertSame($code, ActionOutcome::refused($cause)->code);
        self::assertMatchesRegularExpression(ActionOutcome::CODE_PATTERN, $code);
    }

    /** @return iterable<string, array{string, string}> */
    public static function causes(): iterable
    {
        yield 'a code stays' => ['no-pull-request', 'no-pull-request'];
        yield 'an underscore cause' => ['api_failed_rate_limited', 'api-failed-rate-limited'];
        yield 'case and runs of other characters' => ['  Rate  LIMITED!!_now ', 'rate-limited-now'];
        yield 'a leading digit' => ['422_unprocessable', 'code-422-unprocessable'];
        yield 'nothing left' => ['__ !', 'unknown'];
        yield 'too long' => [str_repeat('a', 63).'_b'.str_repeat('c', 10), str_repeat('a', 63)];
    }
}
