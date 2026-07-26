<?php

declare(strict_types=1);

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ApiToken> */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findOneByRawToken(string $rawToken): ?ApiToken
    {
        // revokedAt: null excludes revoked tokens — a revoked token's row survives
        // (see ApiToken::revoke()) but must never authenticate again.
        return $this->findOneBy(['tokenHash' => hash('sha256', $rawToken), 'revokedAt' => null]);
    }
}
