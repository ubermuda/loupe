<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** The warning each card of a project shows for its last run outcome. */
final readonly class CardRunWarnings
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /**
     * One query for the whole board, keyed by card id.
     *
     * @return array<string, CardRunWarning>
     */
    public function forProject(Project $project): array
    {
        $warnings = [];
        foreach ($this->workerRuns->findWarningRowsOfProject($project) as $row) {
            $warnings[$row['card_id']] = new CardRunWarning($row['id'], WorkerRunState::from($row['state']), $row['output'], new \DateTimeImmutable($row['closed_at']));
        }

        return $warnings;
    }

    /** One card placed alone reads its own warning, not the whole board's. */
    public function forCard(Project $project, Uuid $cardId): ?CardRunWarning
    {
        $row = $this->workerRuns->findWarningRowOfCard($project, $cardId);

        return null === $row ? null : new CardRunWarning($row['id'], WorkerRunState::from($row['state']), $row['output'], new \DateTimeImmutable($row['closed_at']));
    }
}
