<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\OAuth\ClientMetadata\HostResolver;

/** Answers DNS from a fixed table, so no test depends on the network. */
final readonly class FakeHostResolver implements HostResolver
{
    /** @param array<string, list<string>> $addresses */
    public function __construct(
        private array $addresses,
    ) {
    }

    #[\Override]
    public function resolve(string $host): array
    {
        return $this->addresses[$host] ?? [];
    }
}
