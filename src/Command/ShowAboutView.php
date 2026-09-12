<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UpdateStatus;

final readonly class ShowAboutView
{
    public function __construct(
        public ?string $version,
        public ?UpdateStatus $update,
    ) {
    }
}
