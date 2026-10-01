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
    public function insertIfMissing(Uuid $projectId, Uuid $forgePullRequestId, string $noticeKey, \DateTimeImmutable $createdAt): ?Uuid
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO board_pull_request_notices (id, project_id, forge_pull_request_id, notice_key, state, attempts, created_at)
             VALUES (:id, :project, :forgePullRequest, :noticeKey, :state, 0, :createdAt)
             ON CONFLICT (forge_pull_request_id, notice_key) DO NOTHING RETURNING id',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'project' => $projectId->toRfc4122(),
                'forgePullRequest' => $forgePullRequestId->toRfc4122(),
                'noticeKey' => $noticeKey,
                'state' => PullRequestCommentState::Pending->value,
                'createdAt' => $createdAt,
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return \is_string($id) ? Uuid::fromString($id) : null;
    }
}
