<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Command;

use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticInterface;
use App\Module\Diagnostics\DiagnosticState;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Answers the one question a fresh self-hosted instance cannot answer for
 * itself: is the surrounding infrastructure — mail, the messenger worker, the
 * Mercure hub, Stripe — actually wired up, or has it been accepted silently?
 *
 * Every check reports what was *observed*. Where nothing can be observed from a
 * web request the result is DiagnosticState::Unknown with a sentence saying so,
 * never a green tick.
 *
 * The checks are collected by tag, so this class knows none of them by name and
 * a module can contribute its own without editing anything here.
 */
final readonly class RunDiagnosticsHandler
{
    /** @param iterable<DiagnosticInterface> $checks */
    public function __construct(
        #[AutowireIterator('app.diagnostic', defaultPriorityMethod: 'priority')]
        private iterable $checks,
    ) {
    }

    public function __invoke(): RunDiagnosticsView
    {
        $diagnostics = [];
        $overall = DiagnosticState::Ok;

        foreach ($this->checks as $check) {
            $diagnostic = $check();
            if (!$diagnostic instanceof Diagnostic) {
                continue;
            }

            $diagnostics[] = $diagnostic;
            if ($diagnostic->state->severity() > $overall->severity()) {
                $overall = $diagnostic->state;
            }
        }

        return new RunDiagnosticsView(checks: $diagnostics, overall: $overall);
    }
}
