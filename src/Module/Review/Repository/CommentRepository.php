<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * @return list<Comment>
     */
    public function findOpenByVersion(DocumentVersion $version): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.version = :version')
            ->andWhere('c.resolved = :resolved')
            ->setParameter('version', $version)
            ->setParameter('resolved', false)
            ->orderBy('c.anchor.offsetHint', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
