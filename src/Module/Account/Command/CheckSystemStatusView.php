<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class CheckSystemStatusView
{
    /**
     * @param list<SystemCheck> $checks
     * @param SystemCheckState  $overall the worst state among $checks, for the page-level summary
     */
    public function __construct(
        public array $checks,
        public SystemCheckState $overall,
    ) {
    }
}
