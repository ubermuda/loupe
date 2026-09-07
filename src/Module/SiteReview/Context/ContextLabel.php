<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

/**
 * What the widget tells a reviewer their comment will attach to.
 *
 * $url is optional because a resolver may know a name without having a page to
 * send anyone to.
 */
final readonly class ContextLabel
{
    public function __construct(
        public string $label,
        public ?string $url = null,
    ) {
    }
}
