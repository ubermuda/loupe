<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\GrantedCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<GrantedCredential> */
class GrantedCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GrantedCredential::class);
    }

    /**
     * The row for a handle, created on first sight.
     *
     * The insert goes through the connection rather than the EntityManager,
     * because the caller is the audit actor provider: a flush there would carry
     * whatever else the request has pending. Two requests can race on a new
     * handle, so the insert yields to the winner and both then read its row.
     */
    public function findOrCreate(string $handle, User $owner): GrantedCredential
    {
        $found = $this->findOneBy(['handle' => $handle]);
        if (null !== $found) {
            return $found;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO oauth_credentials (id, handle, owner_id, created_at) VALUES (:id, :handle, :ownerId, :createdAt) ON CONFLICT (handle) DO NOTHING',
            [
                'id' => (string) Uuid::v7(),
                'handle' => $handle,
                'ownerId' => (string) ($owner->id ?? throw new \LogicException('a persisted user always has an id')),
                'createdAt' => new \DateTimeImmutable(),
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return $this->findOneBy(['handle' => $handle])
            ?? throw new \LogicException('the credential row is missing right after its insert.');
    }

    public function deleteForOwner(Uuid $ownerId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM oauth_credentials WHERE owner_id = :ownerId',
            ['ownerId' => $ownerId->toRfc4122()],
        );
    }
}
