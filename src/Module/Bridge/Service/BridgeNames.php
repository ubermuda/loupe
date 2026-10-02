<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\BridgeRepository;
use Symfony\Component\Uid\Uuid;

/** The names a page shows for the bridges it lists, read in one query. */
final readonly class BridgeNames
{
    public function __construct(
        private BridgeRepository $bridges,
    ) {
    }

    /**
     * @param list<Uuid|string> $ids
     *
     * @return array<string, string> RFC 4122 bridge id => the name it holds; a bridge that holds none has no key
     */
    public function forOwner(User $owner, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $names = [];
        $uuids = array_map(static fn (Uuid|string $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString($id), $ids);
        foreach ($this->bridges->findByOwnerAndIds($owner, $uuids) as $bridge) {
            if (null !== $bridge->name) {
                $names[$bridge->id->toRfc4122()] = $bridge->name;
            }
        }

        return $names;
    }
}
