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
     * Each row is one card that links one of the next `$limit` ready pull requests that passed their delay and were not announced for that ready time.
     *
     * @return list<array{pullRequestId: string, readySince: string, projectId: string, cardId: string}>
     */
    public function findDue(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<array{pull_request_id: string, ready_since: string, project_id: string, card_id: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT pr.id AS pull_request_id, pr.ready_since, pr.project_id, card.id AS card_id
            FROM (
                SELECT due.*
                FROM forge_pull_requests due
                LEFT JOIN board_automation_settings settings ON settings.project_id = due.project_id
                WHERE due.state = :open
                    AND due.ready_to_merge
                    AND due.ready_since IS NOT NULL
                    AND (due.stuck_announced_for IS NULL OR due.stuck_announced_for <> due.ready_since)
                    AND EXISTS (SELECT 1 FROM board_card_pull_requests linked JOIN board_cards linked_card ON linked_card.id = linked.card_id WHERE linked_card.project_id = due.project_id AND linked.forge = due.forge AND LOWER(linked.repository) = due.repository AND linked.number = due.number)
                    AND due.ready_since + make_interval(mins => COALESCE(settings.stuck_delay_minutes, :defaultDelay)) <= :now
                ORDER BY due.ready_since, due.id
                LIMIT :limit
            ) pr
            JOIN board_card_pull_requests link ON link.forge = pr.forge AND LOWER(link.repository) = pr.repository AND link.number = pr.number
            JOIN board_cards card ON card.id = link.card_id AND card.project_id = pr.project_id',
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

    /** Records the announcement, unless the pull request turned ready again or the board delay grew since the read. */
    public function markAnnounced(string $pullRequestId, string $readySince, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE forge_pull_requests SET stuck_announced_for = ready_since
            WHERE id = :id
                AND ready_since = :readySince
                AND ready_since + make_interval(mins => COALESCE((SELECT settings.stuck_delay_minutes FROM board_automation_settings settings WHERE settings.project_id = forge_pull_requests.project_id), :defaultDelay)) <= :now',
            [
                'id' => $pullRequestId,
                'readySince' => $readySince,
                'defaultDelay' => BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES,
                'now' => $now->format(self::TIME_FORMAT),
            ],
        );
    }

    /**
     * Lets the next sweep announce every ready pull request of the project again, under the new delay.
     *
     * @return list<string> the ids of the cards that link a ready pull request of the project
     */
    public function clearAnnouncements(Project $project): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'UPDATE forge_pull_requests SET stuck_announced_for = NULL WHERE project_id = :project AND stuck_announced_for IS NOT NULL',
            ['project' => (string) $project->id],
        );

        return array_map(strval(...), $connection->fetchFirstColumn(
            'SELECT DISTINCT card.id
            FROM forge_pull_requests pr
            JOIN board_card_pull_requests link ON link.forge = pr.forge AND LOWER(link.repository) = pr.repository AND link.number = pr.number
            JOIN board_cards card ON card.id = link.card_id AND card.project_id = pr.project_id
            WHERE pr.project_id = :project AND pr.state = :open AND pr.ready_to_merge',
            ['project' => (string) $project->id, 'open' => 'open'],
        ));
    }
}
