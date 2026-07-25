<?php

declare(strict_types=1);

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\WaitlistEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WaitlistEntry>
 */
class WaitlistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WaitlistEntry::class);
    }

    public function findOneByEmail(string $email): ?WaitlistEntry
    {
        return $this->findOneBy(['email' => strtolower($email)]);
    }

    public function findOneByValidInviteToken(string $token): ?WaitlistEntry
    {
        $entry = $this->findOneBy(['inviteTokenHash' => hash('sha256', $token)]);

        return null !== $entry && $entry->isInviteTokenValid($token) ? $entry : null;
    }

    /**
     * Entries eligible for (re-)invitation: never invited, or invited with an
     * invite link that has since expired unused. Never a converted entry.
     *
     * @return list<WaitlistEntry>
     */
    public function findOldestUninvited(int $count): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.convertedAt IS NULL')
            ->andWhere('w.invitedAt IS NULL OR w.inviteExpiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('w.createdAt', 'ASC')
            ->setMaxResults($count)
            ->getQuery()
            ->getResult();
    }

    /** @return Paginator<WaitlistEntry> */
    public function findPaginated(int $page, int $perPage, string $sort, string $dir): Paginator
    {
        $qb = $this->createQueryBuilder('w')
            ->orderBy('w.'.$sort, 'asc' === $dir ? 'ASC' : 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb->getQuery());
    }
}
