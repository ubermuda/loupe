<?php

declare(strict_types=1);

namespace App\Tests\Module\Diagnostics\Command;

use App\Module\Diagnostics\Command\RunDiagnosticsHandler;
use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticInterface;
use App\Module\Diagnostics\DiagnosticState;
use PHPUnit\Framework\TestCase;

/**
 * The aggregator's own behaviour, independent of what any real check observes:
 * it reports the worst state it saw and drops the checks that do not apply.
 */
final class RunDiagnosticsAggregationTest extends TestCase
{
    private function check(?Diagnostic $diagnostic): DiagnosticInterface
    {
        return new readonly class($diagnostic) implements DiagnosticInterface {
            public function __construct(
                private ?Diagnostic $diagnostic,
            ) {
            }

            #[\Override]
            public function __invoke(): ?Diagnostic
            {
                return $this->diagnostic;
            }

            #[\Override]
            public static function priority(): int
            {
                return 0;
            }
        };
    }

    public function test_a_check_that_does_not_apply_is_left_out_entirely(): void
    {
        $handler = new RunDiagnosticsHandler([
            $this->check(new Diagnostic('present', DiagnosticState::Ok, 'detail.key')),
            $this->check(null),
        ]);

        $view = $handler();

        self::assertCount(1, $view->checks);
        self::assertSame('present', $view->checks[0]->key);
    }

    public function test_the_overall_state_is_the_worst_one_reported(): void
    {
        $handler = new RunDiagnosticsHandler([
            $this->check(new Diagnostic('a', DiagnosticState::Ok, 'detail.key')),
            $this->check(new Diagnostic('b', DiagnosticState::Failed, 'detail.key')),
            $this->check(new Diagnostic('c', DiagnosticState::Warning, 'detail.key')),
        ]);

        self::assertSame(DiagnosticState::Failed, $handler()->overall);
    }

    public function test_an_all_clear_report_is_ok(): void
    {
        $handler = new RunDiagnosticsHandler([
            $this->check(new Diagnostic('a', DiagnosticState::Ok, 'detail.key')),
        ]);

        self::assertSame(DiagnosticState::Ok, $handler()->overall);
    }

    /** Nothing to report is not a failure — a report with no checks is green. */
    public function test_no_checks_at_all_is_ok(): void
    {
        self::assertSame(DiagnosticState::Ok, (new RunDiagnosticsHandler([]))()->overall);
    }

    public function test_the_report_keeps_the_order_the_checks_arrive_in(): void
    {
        $handler = new RunDiagnosticsHandler([
            $this->check(new Diagnostic('first', DiagnosticState::Ok, 'detail.key')),
            $this->check(null),
            $this->check(new Diagnostic('second', DiagnosticState::Ok, 'detail.key')),
        ]);

        self::assertSame(['first', 'second'], array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->key,
            $handler()->checks,
        ));
    }
}
