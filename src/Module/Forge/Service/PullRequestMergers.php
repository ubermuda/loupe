<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestMergers
{
    /** @param iterable<PullRequestMerger> $mergers */
    public function __construct(
        #[AutowireIterator('app.pull_request_merger')]
        private iterable $mergers,
    ) {
    }

    public function for(string $forge): ?PullRequestMerger
    {
        foreach ($this->mergers as $merger) {
            if ($merger->supports($forge)) {
                return $merger;
            }
        }

        return null;
    }
}
