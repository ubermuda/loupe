<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use Symfony\Component\Uid\Uuid;

/** The labels a page shows for the bridges it lists, read in one query. */
final readonly class BridgeLabels
{
    public function __construct(
        private BridgeRepository $bridges,
    ) {
    }

    /**
     * @param list<Uuid|string> $ids
     *
     * @return array<string, string> RFC 4122 bridge id => its label, for every id, including one with no bridge row
     */
    public function forOwner(User $owner, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $uuids = array_map(static fn (Uuid|string $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString($id), $ids);
        $labels = [];
        foreach ($uuids as $uuid) {
            $labels[$uuid->toRfc4122()] = Bridge::labelFor($uuid, null);
        }
        foreach ($this->bridges->findByOwnerAndIds($owner, $uuids) as $bridge) {
            $labels[$bridge->id->toRfc4122()] = $bridge->label;
        }

        return $labels;
    }
}
