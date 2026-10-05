<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

/** The facts of the test provider. The condition reads $ready alone, and the fingerprint reads both. */
final readonly class ProvidedFacts
{
    public function __construct(
        public bool $ready = false,
        public int $version = 1,
    ) {
    }
}
