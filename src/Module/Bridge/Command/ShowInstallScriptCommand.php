<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

final readonly class ShowInstallScriptCommand
{
    /** @param string $loupeUrl the base URL of this instance, with no trailing slash */
    public function __construct(
        public string $loupeUrl,
    ) {
    }
}
