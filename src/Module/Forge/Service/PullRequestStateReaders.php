<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestStateReaders
{
    /** @param iterable<PullRequestStateReader> $readers */
    public function __construct(
        #[AutowireIterator('app.pull_request_state_reader')]
        private iterable $readers,
    ) {
    }

    public function for(string $forge): ?PullRequestStateReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($forge)) {
                return $reader;
            }
        }

        return null;
    }
}
