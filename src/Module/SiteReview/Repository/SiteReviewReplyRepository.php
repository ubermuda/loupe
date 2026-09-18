<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewReply;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/** @extends ServiceEntityRepository<SiteReviewReply> */
class SiteReviewReplyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReviewReply::class);
    }

    /**
     * @param list<SiteReviewComment> $comments
     *
     * @return list<SiteReviewReply>
     */
    public function findForComments(array $comments): array
    {
        if ([] === $comments) {
            return [];
        }

        return $this->createQueryBuilder('reply')
            ->addSelect('author')
            ->join('reply.author', 'author')
            ->andWhere('reply.comment IN (:comments)')
            ->setParameter('comments', $comments)
            ->orderBy('reply.createdAt', 'ASC')
            ->addOrderBy('reply.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return iterable<SiteReviewReply> */
    public function findByOwner(User $owner): iterable
    {
        return $this->createQueryBuilder('reply')
            ->join('reply.comment', 'comment')
            ->join('comment.project', 'project')
            ->andWhere('project.owner = :owner')
            ->setParameter('owner', $owner->id, UuidType::NAME)
            ->orderBy('reply.createdAt', 'ASC')
            ->addOrderBy('reply.id', 'ASC')
            ->getQuery()->toIterable();
    }
}
