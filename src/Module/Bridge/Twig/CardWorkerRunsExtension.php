<?php

declare(strict_types=1);

namespace App\Module\Bridge\Twig;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\CardRunWarnings;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Bridge\View\CardUsageTotal;
use App\Module\Bridge\View\WorkerRunControls;
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
        private readonly WorkerRunUsageRepository $workerRunUsages,
        private readonly ClockInterface $clock,
        private readonly CardRunWarnings $runWarnings,
        private readonly WorkerRunControls $controls,
        private readonly CardHolds $cardHolds,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('card_worker_runs', $this->cardWorkerRuns(...)),
            new TwigFunction('card_run_warnings', $this->cardRunWarnings(...)),
            new TwigFunction('card_run_warning', $this->cardRunWarning(...)),
            new TwigFunction('card_usage_total', $this->cardUsageTotal(...)),
            new TwigFunction('card_held', $this->cardHeld(...)),
        ];
    }

    /** @return array<string, CardRunWarning> */
    public function cardRunWarnings(Project $project): array
    {
        return $this->runWarnings->forProject($project);
    }

    public function cardRunWarning(Project $project, Uuid $cardId): ?CardRunWarning
    {
        return $this->runWarnings->forCard($project, $cardId);
    }

    /** @return list<WorkerRunListItem> */
    public function cardWorkerRuns(Project $project, string $cardId): array
    {
        if (!Uuid::isValid($cardId)) {
            return [];
        }

        $now = $this->clock->now();
        $runs = $this->workerRuns->findOpenForCard($project, Uuid::fromString($cardId), self::LIMIT);
        $controls = $this->controls->forRuns($project, $runs);

        return array_map(
            // The card page shows neither history nor its own title, so it loads none.
            static fn (WorkerRun $run): WorkerRunListItem => new WorkerRunListItem($run, $now, [], null, $controls[(string) $run->id] ?? null),
            $runs,
        );
    }

    public function cardHeld(Project $project, string $cardId): bool
    {
        return Uuid::isValid($cardId) && $this->cardHolds->isHeld($project, Uuid::fromString($cardId));
    }

    /** Every run of the card counts, the finished ones too. */
    public function cardUsageTotal(Project $project, string $cardId): CardUsageTotal
    {
        if (!Uuid::isValid($cardId)) {
            return new CardUsageTotal(known: false);
        }

        $card = Uuid::fromString($cardId);
        $sums = $this->workerRunUsages->sumForCard($project, $card);
        $runs = $this->workerRuns->findUsageStateOfCard($project, $card);

        return new CardUsageTotal(
            known: $runs['reported'] || $sums['rows'] > 0,
            costUsd: 0 === $sums['rows'] ? '0' : $sums['cost'],
            inputTokens: $sums['input'],
            outputTokens: $sums['output'],
            cacheReadTokens: $sums['cacheRead'],
            cacheWriteTokens: $sums['cacheWrite'],
            partialRuns: $runs['partial'],
            estimated: $sums['estimated'],
        );
    }
}
