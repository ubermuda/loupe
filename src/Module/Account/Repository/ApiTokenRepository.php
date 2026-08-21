<?php

declare(strict_types=1);

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /** Tokens that can still authenticate; a revoked token's row survives but must not be counted. */
    public function countActiveByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner, 'revokedAt' => null]);
    }

    /**
     * Records API token usage, but only if the last recorded use is missing or
     * older than $staleThreshold. Runs as a targeted DBAL UPDATE rather than an
     * entity flush() — this is called on every Bearer-authenticated request, so
     * an unconditional write would hammer one row on every widget page view.
     */
    public function touchLastUsedAt(Uuid $id, \DateTimeImmutable $now, \DateTimeImmutable $staleThreshold): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE api_tokens SET last_used_at = :now WHERE id = :id AND (last_used_at IS NULL OR last_used_at < :threshold)',
            [
                'now' => $now,
                'id' => (string) $id,
                'threshold' => $staleThreshold,
            ],
            [
                'now' => Types::DATETIME_IMMUTABLE,
                'threshold' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }
}
