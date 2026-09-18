<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<CardPullRequest> */
class CardPullRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardPullRequest::class);
    }

    public function findOneInProject(string $id, Project $project): ?CardPullRequest
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->createQueryBuilder('link')
            ->join('link.card', 'card')
            ->andWhere('link.id = :id')
            ->andWhere('card.project = :project')
            ->setParameter('id', Uuid::fromString($id), UuidType::NAME)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findUrlForUpdate(CardPullRequest $link): ?string
    {
        $row = $this->createQueryBuilder('link')
            ->select('link.url')
            ->andWhere('link.id = :id')
            ->setParameter('id', $link->id, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return null === $row ? null : $row['url'];
    }
}
