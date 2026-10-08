<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Command;

use App\Module\DesignSystem\ComponentEntry;
use App\Module\DesignSystem\Token\TokenGroup;

final readonly class StyleguideDetailView
{
    /**
     * @param list<TokenGroup>     $groups
     * @param list<ComponentEntry> $components
     */
    public function __construct(
        public array $groups,
        public array $components,
    ) {
    }
}
