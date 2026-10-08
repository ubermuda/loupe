<?php

declare(strict_types=1);

namespace App\Module\GitHub\Repository;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubUserConnection;
use App\Module\GitHub\Service\GitHubUserConnectionSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<GitHubUserConnection> */
final class GitHubUserConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GitHubUserConnection::class);
    }

    public function findOneByUser(User $user): ?GitHubUserConnection
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Selects no token, so an unreadable encryption key cannot break a page or the export.
     */
    public function findSummaryByUser(User $user): ?GitHubUserConnectionSummary
    {
        return $this->getEntityManager()
            ->createQuery('SELECT NEW '.GitHubUserConnectionSummary::class.'(c.login, c.connectedAt, c.expiredAt, c.accessTokenExpiresAt, c.refreshTokenExpiresAt) FROM '.GitHubUserConnection::class.' c WHERE c.user = :user')
            ->setParameter('user', $user)
            ->getOneOrNullResult();
    }

    /** Deletes without hydrating the row, so an unreadable encryption key cannot block it. */
    public function deleteForUser(Uuid $userId): void
    {
        $this->getEntityManager()
            ->createQuery('DELETE '.GitHubUserConnection::class.' c WHERE c.user = :user')
            ->setParameter('user', $userId, UuidType::NAME)
            ->execute();
    }
}
