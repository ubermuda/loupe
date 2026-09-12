<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

final readonly class ListAdvertisedToolsView
{
    /** @param list<array{name: string, descriptionKey: string}> $tools */
    public function __construct(
        public array $tools,
    ) {
    }
}
