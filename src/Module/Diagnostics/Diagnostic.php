<?php

declare(strict_types=1);

namespace App\Module\Diagnostics;

/**
 * One line of the diagnostics report: what was checked, what came back, and a
 * translatable sentence explaining the result.
 *
 * `$detail` and `$detailParameters` are a translation key and its placeholders
 * rather than a rendered string so the page stays translatable, and so a check
 * never accidentally puts a secret (a DSN, a key) on screen.
 */
final readonly class Diagnostic
{
    /**
     * @param non-empty-string      $key              identifies the check in markup and translation keys
     * @param non-empty-string      $detail           translation key of the explanatory sentence
     * @param array<string, string> $detailParameters placeholders for $detail
     */
    public function __construct(
        public string $key,
        public DiagnosticState $state,
        public string $detail,
        public array $detailParameters = [],
    ) {
    }
}
