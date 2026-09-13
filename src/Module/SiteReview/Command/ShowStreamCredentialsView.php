<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

final readonly class ShowStreamCredentialsView
{
    /** @param list<StreamProjectView> $projects */
    public function __construct(
        public string $hubUrl,
        public string $jwt,
        public array $projects,
    ) {
    }
}
