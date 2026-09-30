<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestCommenters
{
    /** @param iterable<PullRequestCommenter> $commenters */
    public function __construct(
        #[AutowireIterator('app.pull_request_commenter')]
        private iterable $commenters,
    ) {
    }

    public function for(string $forge): ?PullRequestCommenter
    {
        foreach ($this->commenters as $commenter) {
            if ($commenter->supports($forge)) {
                return $commenter;
            }
        }

        return null;
    }
}
