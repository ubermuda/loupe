<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
        ?Uuid $forgePullRequestId,
        \DateTimeImmutable $createdAt,
    ): ?Uuid {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO board_pull_request_comments (id, project_id, run_id, card_id, forge, repository, number, head_sha, reason, fix_round, forge_pull_request_id, state, attempts, created_at)
             VALUES (:id, :project, :run, :card, :forge, :repository, :number, :headSha, :reason, :fixRound, :forgePullRequest, :state, 0, :createdAt)
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
                'forgePullRequest' => $forgePullRequestId?->toRfc4122(),
                'state' => PullRequestCommentState::Pending->value,
                'createdAt' => $createdAt,
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return \is_string($id) ? Uuid::fromString($id) : null;
    }

    /** @return list<WorkerRun> the open worker runs of the work kind on the card that have no comment row, by id */
    public function findUncommentedOpenRuns(Uuid $cardId, string $workKind): array
    {
        /** @var list<WorkerRun> $runs */
        $runs = $this->getEntityManager()->createQuery(
            'SELECT r FROM '.WorkerRun::class.' r LEFT JOIN '.PullRequestComment::class.' comment WITH comment.runId = r.id
            WHERE r.subjectType = :cardSubject AND r.subjectId = :cardId AND r.kind = :kind AND r.workKind = :workKind
                AND r.state IN (:openStates) AND comment.id IS NULL
            ORDER BY r.id ASC',
        )
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('kind', WorkerRunKind::Worker->value)
            ->setParameter('workKind', $workKind)
            ->setParameter('openStates', array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates()))
            ->getResult();

        return $runs;
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

    /**
     * Points the unposted rows of one repository in one project at its new
     * path, so the post still finds its pull request after a rename.
     */
    public function repoint(Uuid $projectId, string $forge, string $from, string $to): int
    {
        return (int) $this->createQueryBuilder('comment')
            ->update()
            ->set('comment.repository', ':to')
            ->andWhere('comment.project = :project')
            ->andWhere('comment.forge = :forge')
            ->andWhere('LOWER(comment.repository) = :from')
            ->andWhere('comment.state IN (:states)')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forge', $forge)
            ->setParameter('from', mb_strtolower($from))
            ->setParameter('to', $to)
            ->setParameter('states', [PullRequestCommentState::Pending->value, PullRequestCommentState::Failed->value])
            ->getQuery()
            ->execute();
    }
}
