<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestStateWriters
{
    /** @param iterable<PullRequestStateWriter> $writers */
    public function __construct(
        #[AutowireIterator('app.pull_request_state_writer')]
        private iterable $writers,
    ) {
    }

    public function for(string $forge): ?PullRequestStateWriter
    {
        foreach ($this->writers as $writer) {
            if ($writer->supports($forge)) {
                return $writer;
            }
        }

        return null;
    }
}
