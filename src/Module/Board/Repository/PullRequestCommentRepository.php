<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PullRequestComment> */
class PullRequestCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PullRequestComment::class);
    }

    /** Answers the id of the new pending row, or null when the run already has one. */
    public function insertIfMissing(
        Uuid $projectId,
        Uuid $runId,
        Uuid $cardId,
        string $forge,
        string $repository,
        int $number,
        ?string $headSha,
        ?string $reason,
        ?int $fixRound,
        \DateTimeImmutable $createdAt,
    ): ?Uuid {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO board_pull_request_comments (id, project_id, run_id, card_id, forge, repository, number, head_sha, reason, fix_round, state, attempts, created_at)
             VALUES (:id, :project, :run, :card, :forge, :repository, :number, :headSha, :reason, :fixRound, :state, 0, :createdAt)
             ON CONFLICT (run_id) DO NOTHING RETURNING id',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'project' => $projectId->toRfc4122(),
                'run' => $runId->toRfc4122(),
                'card' => $cardId->toRfc4122(),
                'forge' => $forge,
                'repository' => $repository,
                'number' => $number,
                'headSha' => $headSha,
                'reason' => $reason,
                'fixRound' => $fixRound,
                'state' => PullRequestCommentState::Pending->value,
                'createdAt' => $createdAt,
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return \is_string($id) ? Uuid::fromString($id) : null;
    }

    /** Answers the posted or failed row that settled last. */
    public function findNewestSettled(Project $project): ?PullRequestComment
    {
        $comment = $this->createQueryBuilder('comment')
            ->addSelect('COALESCE(comment.postedAt, comment.failedAt) AS HIDDEN settledAt')
            ->andWhere('comment.project = :project')
            ->andWhere('comment.state IN (:states)')
            ->setParameter('project', $project)
            ->setParameter('states', [PullRequestCommentState::Posted->value, PullRequestCommentState::Failed->value])
            ->orderBy('settledAt', 'DESC')
            ->addOrderBy('comment.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $comment instanceof PullRequestComment ? $comment : null;
    }
}
