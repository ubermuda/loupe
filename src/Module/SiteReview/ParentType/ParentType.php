<?php

declare(strict_types=1);

namespace App\Module\SiteReview\ParentType;

/** A card type that can have children, as the widget shows it. */
final readonly class ParentType
{
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
