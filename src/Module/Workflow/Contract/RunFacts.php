<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class RunFacts
{
    /**
     * @param list<string> $activeWorkKinds   the kinds of the open or claimed work requests
     * @param list<string> $activeWorkerKinds the work kinds of the open worker runs of the card
     * @param list<string> $parentActiveKinds the work kinds of the open worker runs and the live work requests of the parent, each kind once, empty with no parent
     */
    public function __construct(
        public array $activeWorkKinds,
        public ?string $lastRefusalCode,
        public array $activeWorkerKinds,
        public array $parentActiveKinds,
    ) {
    }
}
