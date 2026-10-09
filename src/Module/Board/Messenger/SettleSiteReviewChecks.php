<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use Symfony\Component\Uid\Uuid;

final readonly class SettleSiteReviewChecks
{
    public function __construct(
        public Uuid $projectId,
    ) {
    }
}
