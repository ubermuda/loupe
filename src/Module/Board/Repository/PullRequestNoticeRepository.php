<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Entity\PullRequestNotice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PullRequestNotice> */
class PullRequestNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PullRequestNotice::class);
    }

    /** Answers the id of the new pending row, or null when the pull request already has one for the key. */
    public function insertIfMissing(Uuid $projectId, Uuid $forgePullRequestId, string $forge, string $repository, int $number, string $noticeKey, \DateTimeImmutable $createdAt): ?Uuid
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO board_pull_request_notices (id, project_id, forge_pull_request_id, forge, repository, number, notice_key, state, attempts, created_at)
             VALUES (:id, :project, :forgePullRequest, :forge, :repository, :number, :noticeKey, :state, 0, :createdAt)
             ON CONFLICT (project_id, forge, repository, number, notice_key) DO NOTHING RETURNING id',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'project' => $projectId->toRfc4122(),
                'forgePullRequest' => $forgePullRequestId->toRfc4122(),
                'forge' => $forge,
                'repository' => $repository,
                'number' => $number,
                'noticeKey' => $noticeKey,
                'state' => PullRequestCommentState::Pending->value,
                'createdAt' => $createdAt,
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return \is_string($id) ? Uuid::fromString($id) : null;
    }

    /** Whether the pull request has a notice for the key, whatever its state. */
    public function hasKey(Uuid $projectId, string $forge, string $repository, int $number, string $noticeKey): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM board_pull_request_notices WHERE project_id = :project AND forge = :forge AND repository = :repository AND number = :number AND notice_key = :noticeKey',
            ['project' => $projectId->toRfc4122(), 'forge' => $forge, 'repository' => $repository, 'number' => $number, 'noticeKey' => $noticeKey],
        );
    }

    /**
     * Points every notice of one repository at its new path, posted ones included, so a rename keeps one notice per key.
     * A row that the new path already holds wins, and the old one goes.
     */
    public function repoint(Uuid $projectId, string $forge, string $from, string $to): int
    {
        if (mb_strtolower($from) === mb_strtolower($to)) {
            return 0;
        }

        $parameters = ['project' => $projectId->toRfc4122(), 'forge' => $forge, 'from' => mb_strtolower($from), 'to' => mb_strtolower($to)];
        $connection = $this->getEntityManager()->getConnection();
        // The surviving row keeps a post that the old one made, so its own pending delivery does not post twice.
        $connection->executeStatement(
            'UPDATE board_pull_request_notices new SET state = :posted, posted_at = old.posted_at, failed_at = NULL, cause = NULL
            FROM board_pull_request_notices old WHERE old.project_id = :project AND old.forge = :forge AND old.repository = :from
            AND new.project_id = old.project_id AND new.forge = old.forge AND new.repository = :to AND new.number = old.number
            AND new.notice_key = old.notice_key AND old.state = :posted AND new.state <> :posted',
            $parameters + ['posted' => PullRequestCommentState::Posted->value],
        );
        $connection->executeStatement(
            'DELETE FROM board_pull_request_notices old WHERE old.project_id = :project AND old.forge = :forge AND old.repository = :from
            AND EXISTS (SELECT 1 FROM board_pull_request_notices new WHERE new.project_id = old.project_id AND new.forge = old.forge
                AND new.repository = :to AND new.number = old.number AND new.notice_key = old.notice_key)',
            $parameters,
        );

        return (int) $connection->executeStatement(
            'UPDATE board_pull_request_notices SET repository = :to WHERE project_id = :project AND forge = :forge AND repository = :from',
            $parameters,
        );
    }
}
