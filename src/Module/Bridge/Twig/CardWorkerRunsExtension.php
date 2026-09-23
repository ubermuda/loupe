<?php

declare(strict_types=1);

namespace App\Module\Bridge\Twig;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\View\WorkerRunListItem;
use App\Module\Project\Entity\Project;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The card drawer lists a card's agent runs through this function, because
 * no module may import Board: a run knows its card by id, never by entity.
 */
final class CardWorkerRunsExtension extends AbstractExtension
{
    public const int LIMIT = 5;

    public function __construct(
        private readonly WorkerRunRepository $workerRuns,
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('card_worker_runs', $this->cardWorkerRuns(...)),
        ];
    }

    /** @return list<WorkerRunListItem> */
    public function cardWorkerRuns(Project $project, string $cardId): array
    {
        if (!Uuid::isValid($cardId)) {
            return [];
        }

        $now = $this->clock->now();

        return array_map(
            // The card shows no history, so it loads none.
            static fn (WorkerRun $run): WorkerRunListItem => new WorkerRunListItem($run, $now, []),
            $this->workerRuns->findRecentForCard($project, Uuid::fromString($cardId), self::LIMIT),
        );
    }
}
