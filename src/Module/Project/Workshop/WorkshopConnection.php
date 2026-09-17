<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

final readonly class WorkshopConnection
{
    public function __construct(
        public string $id,
        public string $cliVersion,
        public bool $quiet,
        public string $url,
    ) {
    }
}
