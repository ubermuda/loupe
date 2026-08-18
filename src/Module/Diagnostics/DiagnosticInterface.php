<?php

declare(strict_types=1);

namespace App\Module\Diagnostics;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One thing worth checking about the surrounding infrastructure. A module
 * contributes its own checks, so the report can cover Billing or Account
 * without the aggregator knowing either exists.
 */
#[AutoconfigureTag('app.diagnostic')]
interface DiagnosticInterface
{
    /**
     * Null when the check does not apply to this instance at all — a feature
     * switched off has nothing to report, and saying so with a green tick or a
     * warning would both be claims the operator did not ask for.
     */
    public function __invoke(): ?Diagnostic;

    /** Report order, highest first. Ordering is presentation, so it lives here. */
    public static function priority(): int;
}
