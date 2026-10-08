<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestCheckWriters
{
    /** @param iterable<PullRequestCheckWriter> $writers */
    public function __construct(
        #[AutowireIterator('app.pull_request_check_writer')]
        private iterable $writers,
    ) {
    }

    public function for(string $forge): ?PullRequestCheckWriter
    {
        foreach ($this->writers as $writer) {
            if ($writer->supports($forge)) {
                return $writer;
            }
        }

        return null;
    }
}
