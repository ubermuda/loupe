<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

/** The bucket rules of the project changed, so the bucket times of its runs need a new computation. */
final readonly class RecomputeBucketTimes
{
    public function __construct(
        public string $projectId,
    ) {
    }
}
