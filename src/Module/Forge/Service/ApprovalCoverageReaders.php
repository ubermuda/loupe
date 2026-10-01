<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ApprovalCoverageReaders
{
    /** @param iterable<ApprovalCoverageReader> $readers */
    public function __construct(
        #[AutowireIterator('app.approval_coverage_reader')]
        private iterable $readers,
    ) {
    }

    public function for(string $forge): ?ApprovalCoverageReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($forge)) {
                return $reader;
            }
        }

        return null;
    }
}
