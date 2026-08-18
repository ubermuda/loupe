<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Command;

use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticState;

final readonly class RunDiagnosticsView
{
    /**
     * @param list<Diagnostic> $checks
     * @param DiagnosticState  $overall the worst state among $checks, for the page-level summary
     */
    public function __construct(
        public array $checks,
        public DiagnosticState $overall,
    ) {
    }
}
