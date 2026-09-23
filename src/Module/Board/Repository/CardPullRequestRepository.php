<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
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

    /**
     * Every card of one project linked to one pull request. A pull request can
     * be linked from more than one card, so a delivery can concern several.
     *
     * @return list<CardPullRequest>
     */
    public function findForPullRequest(Uuid $projectId, Forge $forge, string $repository, int $number): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('card')
            ->join('link.card', 'card')
            ->andWhere('card.project = :project')
            ->andWhere('link.forge = :forge')
            ->andWhere('LOWER(link.repository) = :repository')
            ->andWhere('link.number = :number')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forge', $forge)
            ->setParameter('repository', mb_strtolower($repository))
            ->setParameter('number', $number)
            ->getQuery()
            ->getResult();
    }

    /**
     * Points every link of one repository in one project at its new path, and
     * answers how many moved. The stored path is the key a later delivery joins on.
     */
    public function repoint(Uuid $projectId, Forge $forge, string $from, string $to): int
    {
        // A DQL update cannot join, so the project filter is a subquery.
        return (int) $this->createQueryBuilder('link')
            ->update()
            ->set('link.repository', ':to')
            ->andWhere(\sprintf('link.card IN (SELECT card.id FROM %s card WHERE card.project = :project)', Card::class))
            ->andWhere('link.forge = :forge')
            ->andWhere('LOWER(link.repository) = :from')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('to', $to)
            ->setParameter('forge', $forge)
            ->setParameter('from', mb_strtolower($from))
            ->getQuery()
            ->execute();
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
