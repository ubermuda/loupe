<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestBranchUpdaters
{
    /** @param iterable<PullRequestBranchUpdater> $updaters */
    public function __construct(
        #[AutowireIterator('app.pull_request_branch_updater')]
        private iterable $updaters,
    ) {
    }

    public function for(string $forge): ?PullRequestBranchUpdater
    {
        foreach ($this->updaters as $updater) {
            if ($updater->supports($forge)) {
                return $updater;
            }
        }

        return null;
    }
}
