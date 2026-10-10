<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\View\CardLiveWorkItem;
use App\Module\Project\Entity\Project;

/** The live work requests and the open runs of many cards of one project, in two queries whatever the card count. */
final readonly class CardLiveWork
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /**
     * @param list<string> $cardIds RFC 4122 ids
     *
     * @return array<string, non-empty-list<CardLiveWorkItem>> card id => its work, oldest first; a card with none has no key
     */
    public function forCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $work = [];
        foreach ($this->workRequests->findLiveRowsForCards($cardIds) as $row) {
            $work[$row['card_id']][] = new CardLiveWorkItem($row['kind'], new \DateTimeImmutable($row['created_at']), false);
        }
        foreach ($this->workerRuns->findOpenRowsForCards($project, $cardIds) as $row) {
            $work[$row['card_id']][] = new CardLiveWorkItem($row['work_kind'], new \DateTimeImmutable($row['since']), true);
        }

        return $work;
    }
}
