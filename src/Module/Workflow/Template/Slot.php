<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

final readonly class Slot
{
    /** @param string $label a translation key */
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
