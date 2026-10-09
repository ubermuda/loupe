<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestReviewPosters
{
    /** @param iterable<PullRequestReviewPoster> $posters */
    public function __construct(
        #[AutowireIterator('app.pull_request_review_poster')]
        private iterable $posters,
    ) {
    }

    public function for(string $forge): ?PullRequestReviewPoster
    {
        foreach ($this->posters as $poster) {
            if ($poster->supports($forge)) {
                return $poster;
            }
        }

        return null;
    }
}
