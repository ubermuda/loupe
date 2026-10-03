<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

final readonly class RunFacts
{
    /** @param list<string> $activeWorkKinds the kinds of the open or claimed work requests */
    public function __construct(
        public array $activeWorkKinds,
        public ?string $lastRefusalCode,
    ) {
    }
}
