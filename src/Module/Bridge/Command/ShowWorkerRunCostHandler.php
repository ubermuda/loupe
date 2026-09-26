<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\CostSplit;
use App\Module\Bridge\View\CardCost;
use App\Module\Bridge\View\CostChart;
use App\Module\Bridge\View\CostPart;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ShowWorkerRunCostHandler
{
    public function __construct(
        private FinishedCardSourceInterface $finishedCards,
        private WorkerRunUsageRepository $workerRunUsages,
        private WorkerRunRepository $workerRuns,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ShowWorkerRunCostCommand $command): WorkerRunCostView
    {
        $project = $command->project;
        $query = $command->query;
        $now = $this->clock->now();
        $from = $query->range->startFrom($now);

        $finished = $this->finishedCards->finishedCards($project, $from);
        $cardIds = array_map(static fn (FinishedCard $card): Uuid => $card->id, $finished);

        // The range is on the completion date alone: a card finished this week keeps the runs of last month.
        $parts = [];
        foreach ($this->workerRunUsages->sumByCardPart($project, $cardIds, $query->split, $query->rule, $query->model) as $row) {
            $parts[$row['cardId']][] = new CostPart($row['part'], $row['costMicros'], $row['input'], $row['output'], $row['cacheRead'], $row['cacheWrite'], $row['estimated']);
        }
        // A run with no usage has no model, so the model filter cannot narrow it.
        $partial = $this->workerRuns->countPartialRunsByCard($project, $cardIds, $query->rule);

        $cards = [];
        foreach ($finished as $card) {
            $cardParts = $parts[(string) $card->id] ?? [];
            if ([] !== $cardParts) {
                $cards[] = new CardCost($card, $cardParts, $partial[(string) $card->id] ?? 0);
            }
        }

        $options = $this->workerRunUsages->rulesAndModelsOf($project);
        $costs = array_map(static fn (CardCost $cost): int => $cost->costMicros, $cards);

        return new WorkerRunCostView(
            project: $project,
            query: $query,
            cards: $cards,
            rules: $options['rules'],
            models: $options['models'],
            totalMicros: array_sum($costs),
            medianMicros: self::median($costs),
            chart: [] === $cards ? null : CostChart::build(
                $cards,
                match ($query->split) {
                    CostSplit::None => [''],
                    CostSplit::Rule => $options['rules'],
                    CostSplit::Model => $options['models'],
                },
                $from ?? $cards[0]->card->completedAt,
                $now,
            ),
        );
    }

    /** @param list<int> $values */
    private static function median(array $values): int
    {
        if ([] === $values) {
            return 0;
        }

        sort($values);
        $middle = intdiv(\count($values), 2);

        return 0 === \count($values) % 2 ? intdiv($values[$middle - 1] + $values[$middle], 2) : $values[$middle];
    }
}
