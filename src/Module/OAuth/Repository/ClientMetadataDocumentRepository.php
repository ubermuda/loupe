<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use App\Module\OAuth\Entity\ClientMetadataDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ClientMetadataDocument> */
final class ClientMetadataDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientMetadataDocument::class);
    }

    /** Serialises concurrent first fetches of one client until the transaction ends. */
    public function lockForRegistration(string $clientIdentifier): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:id))', ['id' => 'oauth_cimd:'.$clientIdentifier]);
    }
}
