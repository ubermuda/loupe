<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use Symfony\Component\Uid\Uuid;

/** A pull request lost its last card, so the failed check that Loupe posted on it must stop blocking. */
final readonly class NeutralizeSiteReviewCheck
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
