<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use Symfony\Component\Uid\Uuid;

final readonly class NeutralizeSiteReviewCheckCommand
{
    public function __construct(
        public Uuid $projectId,
        public string $forge,
        public string $repository,
        public int $number,
        public string $headSha,
        public int $runId,
    ) {
    }
}
