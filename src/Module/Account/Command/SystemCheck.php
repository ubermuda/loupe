<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

/**
 * One line of the system-status page: what was checked, what came back, and a
 * translatable sentence explaining the result.
 *
 * `$detail` and `$detailParameters` are a translation key and its placeholders
 * rather than a rendered string so the page stays translatable, and so a check
 * never accidentally puts a secret (a DSN, a key) on screen.
 */
final readonly class SystemCheck
{
    /**
     * @param non-empty-string      $key              identifies the check in markup and translation keys
     * @param non-empty-string      $detail           translation key of the explanatory sentence
     * @param array<string, string> $detailParameters placeholders for $detail
     */
    public function __construct(
        public string $key,
        public SystemCheckState $state,
        public string $detail,
        public array $detailParameters = [],
    ) {
    }
}
