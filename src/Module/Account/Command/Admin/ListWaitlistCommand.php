<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

final readonly class ListWaitlistCommand
{
    public function __construct(
        public int $page,
        public string $sort,
        public string $dir,
    ) {
    }
}
