<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/** A note that a check puts on a range of lines of one file of the pull request. */
final readonly class PullRequestCheckAnnotation
{
    public function __construct(
        public string $path,
        public int $startLine,
        public int $endLine,
        public PullRequestCheckAnnotationLevel $level,
        public string $title,
        public string $message,
    ) {
    }
}
