<?php

declare(strict_types=1);

namespace App\Command;

final readonly class ShowAboutCommand
{
    public function __construct(
        public bool $signedIn,
    ) {
    }
}
