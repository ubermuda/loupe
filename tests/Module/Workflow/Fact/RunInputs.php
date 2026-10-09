<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

/** What a test says about the work of a card. FactsMother turns it into the facts of the providers. */
final readonly class RunInputs
{
    /**
     * @param list<string> $activeWorkKinds
     * @param list<string> $activeWorkerKinds
     * @param list<string> $parentActiveKinds
     */
    public function __construct(
        public array $activeWorkKinds,
        public ?string $lastRefusalCode,
        public array $activeWorkerKinds,
        public array $parentActiveKinds,
    ) {
    }
}
