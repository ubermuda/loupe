<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Experiment\CardOutcome;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\LeftOutReason;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Utils\PageList;
use Symfony\Component\Uid\Uuid;

final readonly class ShowExperimentHandler
{
    public const int PER_PAGE = 20;

    /** The outcomes that count as a stop in the stop rate. */
    private const array STOP_STATES = [WorkerRunState::Blocked, WorkerRunState::Failed, WorkerRunState::NoResult, WorkerRunState::GaveUp];

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private ExperimentPinRepository $experimentPins,
        private ExperimentDefinitionRepository $experimentDefinitions,
        private WorkerRunUsageRepository $workerRunUsages,
        private CardReportSourceInterface $cardReports,
        private CardTitleSourceInterface $cardTitles,
    ) {
    }

    public function __invoke(ShowExperimentCommand $command): ?ExperimentReportView
    {
        $project = $command->project;
        $experiment = $command->experiment;

        /** @var array<string, Uuid> $cardIds */
        $cardIds = [];
        /** @var array<string, string> $pinVariants */
        $pinVariants = [];
        foreach ($this->experimentPins->findOfExperiment($project, $experiment) as $pin) {
            $cardIds[(string) $pin->cardId] = $pin->cardId;
            $pinVariants[(string) $pin->cardId] = $pin->variant;
        }
        /** @var array<string, non-empty-list<WorkerRun>> $experimentRuns oldest first */
        $experimentRuns = [];
        /** @var array<string, list<WorkerRun>> $plainRuns */
        $plainRuns = [];
        /** @var array<string, ?string> $models variant => model of its latest run */
        $models = [];
        foreach ($this->workerRuns->findWorkerRunsOfExperimentCards($project, $experiment) as $run) {
            $id = (string) $run->cardId;
            if ($experiment === $run->experiment) {
                $cardIds[$id] = $run->cardId;
                $experimentRuns[$id][] = $run;
                if (null !== $run->variant) {
                    $models[$run->variant] = $run->requestedModel;
                }
            } elseif (null === $run->experiment) {
                $plainRuns[$id][] = $run;
            }
        }
        if ([] === $cardIds) {
            return null;
        }

        $ids = array_values($cardIds);
        $historyStart = $this->cardReports->historyStartFor($project);
        $outcomes = $this->cardReports->outcomesFor($project, $ids);
        $columns = $this->cardReports->columnsFor($project, $ids);
        $titles = $this->cardTitles->titlesFor($project, $ids);
        $usage = $this->workerRunUsages->sumOfExperimentByCard($project, $experiment);
        $definition = $this->experimentDefinitions->findOneBy(['project' => $project, 'experiment' => $experiment]);
        $weights = null === $definition ? [] : array_column($definition->weights, 'weight', 'name');

        $variantNames = array_merge(array_keys($weights), array_values($pinVariants), array_keys($models));

        /** @var list<array{card: ExperimentCard, lastRunAt: ?\DateTimeImmutable}> $cards */
        $cards = [];
        /** @var array<string, list<array{outcome: CardOutcome, finished: bool, runs: list<WorkerRun>, costMicros: int, outputTokens: int}>> $kept variant => kept cards */
        $kept = [];
        foreach ($cardIds as $id => $cardId) {
            $own = $experimentRuns[$id] ?? [];
            $first = $own[0] ?? null;
            $last = [] === $own ? null : $own[\count($own) - 1];
            $variant = $pinVariants[$id] ?? $first?->variant;
            $leftOut = self::leftOut($own, $plainRuns[$id] ?? [], $historyStart);
            $outcome = $outcomes[$id] ?? new CardOutcome();
            $column = $columns[$id] ?? null;
            $cost = $usage[$id] ?? ['costMicros' => 0, 'outputTokens' => 0];

            $cards[] = [
                'card' => new ExperimentCard(
                    cardId: $cardId,
                    number: $last?->cardNumber,
                    title: $titles[$id] ?? null,
                    variant: $variant,
                    column: $column,
                    runs: \count($own),
                    fixRounds: $outcome->totalFixRounds(),
                    costMicros: $cost['costMicros'],
                    leftOut: $leftOut,
                ),
                'lastRunAt' => $last?->receivedAt,
            ];
            if ([] === $leftOut && null !== $variant) {
                $kept[$variant][] = [
                    'outcome' => $outcome,
                    'finished' => $outcome->merged || true === $column?->terminal,
                    'runs' => $own,
                    'costMicros' => $cost['costMicros'],
                    'outputTokens' => $cost['outputTokens'],
                ];
            }
        }

        $variantNames = array_values(array_unique(array_map(strval(...), $variantNames)));
        sort($variantNames, \SORT_STRING);

        $variants = [];
        $finished = [];
        foreach ($variantNames as $name) {
            $rows = $kept[$name] ?? [];
            $finished[$name] = \count(array_filter($rows, static fn (array $row): bool => $row['finished']));
            $variants[] = new ExperimentVariant(
                name: $name,
                model: $models[$name] ?? null,
                weight: $weights[$name] ?? null,
                cards: \count($rows),
                finishedCards: $finished[$name],
                runs: array_sum(array_map(static fn (array $row): int => \count($row['runs']), $rows)),
                costMicros: array_sum(array_column($rows, 'costMicros')),
            );
        }

        $metrics = $command->withMetrics ? $this->metrics($experiment, $variantNames, $kept, $finished) : [];

        $filtered = array_values(array_filter($cards, static fn (array $row): bool => (null === $command->variant || ($row['card']->included() && $command->variant === $row['card']->variant))
            && (!$command->leftOutOnly || !$row['card']->included())));
        // The latest run first, and a card with a pin and no run last.
        usort($filtered, static fn (array $a, array $b): int => [null === $a['lastRunAt'], $b['lastRunAt'], (string) $a['card']->cardId] <=> [null === $b['lastRunAt'], $a['lastRunAt'], (string) $b['card']->cardId]);

        $total = \count($filtered);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        // A page near PHP_INT_MAX overflows the offset multiplication to a float.
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, self::PER_PAGE));
        $included = \count(array_filter($cards, static fn (array $row): bool => $row['card']->included()));

        return new ExperimentReportView(
            project: $project,
            experiment: $experiment,
            headline: [] === $metrics ? null : self::headline($variantNames, $metrics[ExperimentMetric::COST], $metrics[ExperimentMetric::MERGE_RATE], $metrics[ExperimentMetric::FIX_ROUNDS]),
            variants: $variants,
            metrics: $metrics,
            includedCards: $included,
            leftOutCards: \count($cards) - $included,
            cards: array_column(\array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE), 'card'),
            filteredTotal: $total,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            clampedPage: PageList::clampedPage($page, $total, self::PER_PAGE),
            page: $page,
            variantFilter: $command->variant,
            leftOutOnly: $command->leftOutOnly,
        );
    }

    /**
     * @param list<WorkerRun> $experimentRuns oldest first
     * @param list<WorkerRun> $plainRuns
     *
     * @return list<LeftOutReason>
     */
    private static function leftOut(array $experimentRuns, array $plainRuns, ?\DateTimeImmutable $historyStart): array
    {
        $first = $experimentRuns[0] ?? null;
        $variants = array_unique(array_filter(array_map(static fn (WorkerRun $run): ?string => $run->variant, $experimentRuns), static fn (?string $variant): bool => null !== $variant));

        $reasons = [];
        if ([] !== array_filter($experimentRuns, static fn (WorkerRun $run): bool => null !== $run->switchedFrom)) {
            $reasons[] = LeftOutReason::Switched;
        }
        if (\count($variants) > 1) {
            $reasons[] = LeftOutReason::Mixed;
        }
        if (null !== $first && [] !== array_filter($plainRuns, static fn (WorkerRun $run): bool => $run->cardColumn === $first->cardColumn && $run->receivedAt < $first->receivedAt)) {
            $reasons[] = LeftOutReason::BeforeTest;
        }
        if (null !== $first && (null === $historyStart || $first->receivedAt < $historyStart)) {
            $reasons[] = LeftOutReason::NoHistory;
        }

        return $reasons;
    }

    /**
     * @param list<string>                                                                                                                $variantNames
     * @param array<string, list<array{outcome: CardOutcome, finished: bool, runs: list<WorkerRun>, costMicros: int, outputTokens: int}>> $kept
     * @param array<string, int>                                                                                                          $finished
     *
     * @return array<string, ExperimentMetric>
     */
    private function metrics(string $experiment, array $variantNames, array $kept, array $finished): array
    {
        $merged = [];
        $reasons = [];
        foreach ($variantNames as $name) {
            $merged[$name] = array_values(array_filter($kept[$name] ?? [], static fn (array $row): bool => $row['outcome']->merged));
            foreach ($merged[$name] as $row) {
                $reasons += $row['outcome']->fixRounds;
            }
        }
        $reasons = array_map(strval(...), array_keys($reasons));
        sort($reasons, \SORT_STRING);

        $metric = static function (string $key, callable $interval, array $parts = []) use ($variantNames, $finished): ExperimentMetric {
            $byVariant = [];
            foreach ($variantNames as $name) {
                $byVariant[$name] = $interval($name);
            }

            return new ExperimentMetric($key, $byVariant, self::clear($variantNames, $byVariant, $finished), array_values($parts));
        };
        $bootstrap = static fn (string $key, callable $value): \Closure => static fn (string $name): ?Interval => Stats::bootstrapMean(
            array_values(array_filter(array_map($value, $merged[$name]), static fn (int|float|null $item): bool => null !== $item)),
            $experiment.':'.$key.':'.$name,
        );

        return [
            ExperimentMetric::MERGE_RATE => $metric(ExperimentMetric::MERGE_RATE, static fn (string $name): ?Interval => Stats::wilson(\count($merged[$name]), $finished[$name])),
            ExperimentMetric::STOP_RATE => $metric(ExperimentMetric::STOP_RATE, static function (string $name) use ($kept): ?Interval {
                $closed = array_filter(array_merge(...array_column($kept[$name] ?? [], 'runs')), static fn (WorkerRun $run): bool => $run->state->isOutcome());

                return Stats::wilson(\count(array_filter($closed, static fn (WorkerRun $run): bool => \in_array($run->state, self::STOP_STATES, true))), \count($closed));
            }),
            ExperimentMetric::FIX_ROUNDS => $metric(
                ExperimentMetric::FIX_ROUNDS,
                $bootstrap(ExperimentMetric::FIX_ROUNDS, static fn (array $row): int => $row['outcome']->totalFixRounds()),
                array_map(
                    static fn (string $reason): ExperimentMetric => $metric($reason, $bootstrap(ExperimentMetric::FIX_ROUNDS.':'.$reason, static fn (array $row): int => $row['outcome']->fixRounds[$reason] ?? 0)),
                    $reasons,
                ),
            ),
            ExperimentMetric::COST => $metric(ExperimentMetric::COST, $bootstrap(ExperimentMetric::COST, static fn (array $row): float => $row['costMicros'] / 1_000_000)),
            ExperimentMetric::OUTPUT_TOKENS => $metric(ExperimentMetric::OUTPUT_TOKENS, $bootstrap(ExperimentMetric::OUTPUT_TOKENS, static fn (array $row): int => $row['outputTokens'])),
            ExperimentMetric::HOURS_TO_MERGE => $metric(ExperimentMetric::HOURS_TO_MERGE, $bootstrap(ExperimentMetric::HOURS_TO_MERGE, static fn (array $row): ?float => $row['outcome']->hoursToMerge())),
        ];
    }

    /**
     * @param list<string>             $variantNames
     * @param array<string, ?Interval> $byVariant
     * @param array<string, int>       $finished
     */
    private static function clear(array $variantNames, array $byVariant, array $finished): bool
    {
        if (\count($variantNames) < 2) {
            return false;
        }
        [$a, $b] = $variantNames;
        $intervalA = $byVariant[$a] ?? null;
        $intervalB = $byVariant[$b] ?? null;

        return null !== $intervalA && null !== $intervalB && Stats::isClear($intervalA, $intervalB, $finished[$a] ?? 0, $finished[$b] ?? 0);
    }

    /** @param list<string> $variantNames */
    private static function headline(array $variantNames, ExperimentMetric $cost, ExperimentMetric $mergeRate, ExperimentMetric $fixRounds): ExperimentHeadline
    {
        $cheaper = null;
        $saving = null;
        if (\count($variantNames) >= 2) {
            [$a, $b] = $variantNames;
            $costA = $cost->for($a);
            $costB = $cost->for($b);
            if (null !== $costA && null !== $costB) {
                [$cheaper, $low, $high] = $costA->point <= $costB->point ? [$a, $costA->point, $costB->point] : [$b, $costB->point, $costA->point];
                $saving = $high > 0 ? 1 - $low / $high : null;
            }
        }

        return new ExperimentHeadline($cheaper, $saving, $cost->clear, $mergeRate->clear && $fixRounds->clear);
    }
}
