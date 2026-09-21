<?php

declare(strict_types=1);

namespace App\Module\Board\Forge;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Finds the adapter a delivery's path names. */
final readonly class ForgeAdapters
{
    /** @param iterable<ForgeAdapterInterface> $adapters */
    public function __construct(
        #[AutowireIterator('app.forge_adapter')]
        private iterable $adapters,
    ) {
    }

    public function forSlug(string $slug): ?ForgeAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->forge()->value === $slug) {
                return $adapter;
            }
        }

        return null;
    }
}
