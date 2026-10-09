<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The ready pull requests whose stuck delay has ended, read in one query across all projects.
 *
 * @extends ServiceEntityRepository<ForgePullRequest>
 */
class StuckPullRequestRepository extends ServiceEntityRepository
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForgePullRequest::class);
    }

    /**
     * Each row is one card that links a ready pull request that passed its delay and was not announced for that ready time.
     *
     * @return list<array{pullRequestId: string, readySince: string, projectId: string, cardId: string}>
     */
    public function findDue(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<array{pull_request_id: string, ready_since: string, project_id: string, card_id: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT pr.id AS pull_request_id, pr.ready_since, pr.project_id, card.id AS card_id
            FROM forge_pull_requests pr
            LEFT JOIN board_automation_settings settings ON settings.project_id = pr.project_id
            JOIN board_card_pull_requests link ON link.forge = pr.forge AND LOWER(link.repository) = pr.repository AND link.number = pr.number
            JOIN board_cards card ON card.id = link.card_id AND card.project_id = pr.project_id
            WHERE pr.state = :open
                AND pr.ready_to_merge
                AND pr.ready_since IS NOT NULL
                AND (pr.stuck_announced_for IS NULL OR pr.stuck_announced_for <> pr.ready_since)
                AND pr.ready_since + make_interval(mins => COALESCE(settings.stuck_delay_minutes, :defaultDelay)) <= :now
            ORDER BY pr.ready_since, pr.id
            LIMIT :limit',
            [
                'open' => 'open',
                'defaultDelay' => BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES,
                'now' => $now->format(self::TIME_FORMAT),
                'limit' => $limit,
            ],
        );

        return array_map(static fn (array $row): array => [
            'pullRequestId' => $row['pull_request_id'],
            'readySince' => $row['ready_since'],
            'projectId' => $row['project_id'],
            'cardId' => $row['card_id'],
        ], $rows);
    }

    /** Records the announcement, unless the pull request turned ready again since the read. */
    public function markAnnounced(string $pullRequestId, string $readySince): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE forge_pull_requests SET stuck_announced_for = ready_since WHERE id = :id AND ready_since = :readySince',
            ['id' => $pullRequestId, 'readySince' => $readySince],
        );
    }

    /** Lets the next sweep announce every ready pull request of the project again, under the new delay. */
    public function clearAnnouncements(Project $project): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE forge_pull_requests SET stuck_announced_for = NULL WHERE project_id = :project AND stuck_announced_for IS NOT NULL',
            ['project' => (string) $project->id],
        );
    }
}
