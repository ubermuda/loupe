<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestOpeners
{
    /** @param iterable<PullRequestOpener> $openers */
    public function __construct(
        #[AutowireIterator('app.pull_request_opener')]
        private iterable $openers,
    ) {
    }

    public function for(string $forge): ?PullRequestOpener
    {
        foreach ($this->openers as $opener) {
            if ($opener->supports($forge)) {
                return $opener;
            }
        }

        return null;
    }
}
