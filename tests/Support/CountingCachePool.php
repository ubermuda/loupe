<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A cache pool that counts writes.
 *
 * An item saved with a zero lifetime expires at once, so afterwards it reads
 * exactly like an item nobody wrote. A test that must tell "wrote nothing" from
 * "wrote something that expired" counts the writes instead.
 */
final class CountingCachePool implements CacheItemPoolInterface
{
    public int $saves = 0;

    private readonly ArrayAdapter $inner;

    public function __construct()
    {
        $this->inner = new ArrayAdapter();
    }

    #[\Override]
    public function getItem(string $key): CacheItemInterface
    {
        return $this->inner->getItem($key);
    }

    /**
     * @param list<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    #[\Override]
    public function getItems(array $keys = []): iterable
    {
        return $this->inner->getItems($keys);
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    #[\Override]
    public function clear(): bool
    {
        return $this->inner->clear();
    }

    #[\Override]
    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    #[\Override]
    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        ++$this->saves;

        return $this->inner->save($item);
    }

    #[\Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        ++$this->saves;

        return $this->inner->saveDeferred($item);
    }

    #[\Override]
    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
