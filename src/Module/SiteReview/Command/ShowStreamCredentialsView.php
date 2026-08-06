<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowStreamCredentialsView
{
    public function __construct(
        public ?Project $site,
        public string $hubUrl,
        public string $topic,
        public string $jwt,
    ) {
    }
}
