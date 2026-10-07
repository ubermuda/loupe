<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Experiment\CardOutcome;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\LeftOutReason;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Utils\PageList;
use Symfony\Component\Uid\Uuid;

final readonly class ShowExperimentHandler
{
    public const int PER_PAGE = 20;

    /** The metrics of an experiment whose rule declares none, in display order. */
    public const array DEFAULT_METRICS = [
        ExperimentMetric::MERGE_RATE,
        ExperimentMetric::STOP_RATE,
        ExperimentMetric::FIX_ROUNDS,
        ExperimentMetric::COST,
        ExperimentMetric::OUTPUT_TOKENS,
        ExperimentMetric::HOURS_TO_MERGE,
    ];

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private ExperimentPinRepository $experimentPins,
        private ExperimentDefinitionRepository $experimentDefinitions,
        private WorkerRunFactRepository $workerRunFacts,
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
            $id = (string) $run->subjectId;
            if ($experiment === $run->experiment) {
                $cardIds[$id] = $run->subjectId;
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
        /** @var array<string, list<WorkerRunFact>> $facts */
        $facts = [];
        foreach ($this->workerRunFacts->findOfExperimentCards($project, $experiment) as $fact) {
            $facts[(string) $fact->subjectId][] = $fact;
        }
        $definition = $this->experimentDefinitions->findOneBy(['project' => $project, 'experiment' => $experiment]);
        $weights = null === $definition ? [] : array_column($definition->weights, 'weight', 'name');
        $declared = $definition?->metrics;
        $metricKeys = null === $declared ? self::DEFAULT_METRICS : array_values(array_unique(array_filter($declared, static fn (string $key): bool => null !== Metric::tryFrom($key))));
        $unknownMetrics = null === $declared ? [] : array_values(array_unique(array_filter($declared, static fn (string $key): bool => null === Metric::tryFrom($key))));

        $variantNames = array_merge(array_keys($weights), array_values($pinVariants), array_keys($models));

        /** @var list<array{card: ExperimentCard, lastRunAt: ?\DateTimeImmutable}> $cards */
        $cards = [];
        /** @var array<string, list<array{outcome: CardOutcome, finished: bool, runs: list<WorkerRun>, facts: list<WorkerRunFact>, costMicros: ?int}>> $kept variant => kept cards, a null cost when it is unknown */
        $kept = [];
        foreach ($cardIds as $id => $cardId) {
            $own = $experimentRuns[$id] ?? [];
            $first = $own[0] ?? null;
            $last = [] === $own ? null : $own[\count($own) - 1];
            // A pin can switch before a run reports it, so the runs name the variant that did the work.
            $variant = null === $first ? $pinVariants[$id] ?? null : $first->variant;
            $leftOut = self::leftOut($own, $plainRuns[$id] ?? [], $pinVariants[$id] ?? null, $historyStart);
            $outcome = $outcomes[$id] ?? new CardOutcome();
            $column = $columns[$id] ?? null;
            $ownFacts = $facts[$id] ?? [];
            $costMicros = MetricRowSource::cardSum($ownFacts, Metric::Cost);

            $cards[] = [
                'card' => new ExperimentCard(
                    cardId: $cardId,
                    number: $last?->cardNumber,
                    title: $titles[$id] ?? null,
                    variant: $variant,
                    column: $column,
                    runs: \count($own),
                    fixRounds: $outcome->totalFixRounds(),
                    costMicros: $costMicros,
                    leftOut: $leftOut,
                ),
                'lastRunAt' => $last?->receivedAt,
            ];
            if ([] === $leftOut && null !== $variant) {
                $kept[$variant][] = [
                    'outcome' => $outcome,
                    'finished' => $outcome->merged || true === $column?->terminal,
                    'runs' => $own,
                    'facts' => $ownFacts,
                    'costMicros' => $costMicros,
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
                costMicros: self::totalCost($rows),
            );
        }

        // The headline reads these three, whether the table shows them or not.
        $computed = $command->withMetrics ? $this->metrics($experiment, $variantNames, $kept, $finished, array_values(array_unique([...$metricKeys, ExperimentMetric::COST, ExperimentMetric::MERGE_RATE, ExperimentMetric::FIX_ROUNDS]))) : [];
        $metrics = [];
        foreach ([] === $computed ? [] : $metricKeys as $key) {
            $metrics[$key] = $computed[$key];
        }

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
            headline: [] === $computed ? null : self::headline($variantNames, $computed[ExperimentMetric::COST], $computed[ExperimentMetric::MERGE_RATE], $computed[ExperimentMetric::FIX_ROUNDS]),
            variants: $variants,
            metrics: $metrics,
            declaredMetrics: $declared,
            unknownMetrics: $unknownMetrics,
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
     * Null when no kept card reported usage, so the table does not show a cost of zero.
     *
     * @param list<array{costMicros: ?int}> $rows
     */
    private static function totalCost(array $rows): ?int
    {
        $costs = array_filter(array_column($rows, 'costMicros'), static fn (?int $cost): bool => null !== $cost);

        return [] === $costs ? null : array_sum($costs);
    }

    /**
     * @param list<WorkerRun> $experimentRuns oldest first
     * @param list<WorkerRun> $plainRuns
     *
     * @return list<LeftOutReason>
     */
    private static function leftOut(array $experimentRuns, array $plainRuns, ?string $pinVariant, ?\DateTimeImmutable $historyStart): array
    {
        $first = $experimentRuns[0] ?? null;
        $variants = array_map(static fn (WorkerRun $run): ?string => $run->variant, $experimentRuns);
        if (null !== $first) {
            $variants[] = $pinVariant;
        }
        $variants = array_unique(array_filter($variants, static fn (?string $variant): bool => null !== $variant));

        $reasons = [];
        if ([] !== array_filter($experimentRuns, static fn (WorkerRun $run): bool => null !== $run->switchedFrom)) {
            $reasons[] = LeftOutReason::Switched;
        }
        if (\count($variants) > 1) {
            $reasons[] = LeftOutReason::Mixed;
        }
        if (null !== $first && [] !== array_filter($plainRuns, static fn (WorkerRun $run): bool => $run->workKind === $first->workKind && $run->receivedAt < $first->receivedAt)) {
            $reasons[] = LeftOutReason::BeforeTest;
        }
        if (null !== $first && (null === $historyStart || $first->receivedAt < $historyStart)) {
            $reasons[] = LeftOutReason::NoHistory;
        }
        if (null === $first) {
            $reasons[] = LeftOutReason::NoRun;
        }

        return $reasons;
    }

    /**
     * @param list<string>                                                                                                                          $variantNames
     * @param array<string, list<array{outcome: CardOutcome, finished: bool, runs: list<WorkerRun>, facts: list<WorkerRunFact>, costMicros: ?int}>> $kept
     * @param array<string, int>                                                                                                                    $finished
     * @param list<string>                                                                                                                          $keys         known metric keys
     *
     * @return array<string, ExperimentMetric>
     */
    private function metrics(string $experiment, array $variantNames, array $kept, array $finished, array $keys): array
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

        // A sample gives the range and the card count that the floor of a clear answer reads.
        $metric = static function (string $key, callable $sample, array $parts = []) use ($variantNames): ExperimentMetric {
            $byVariant = [];
            $counts = [];
            foreach ($variantNames as $name) {
                [$byVariant[$name], $counts[$name]] = $sample($name);
            }

            return new ExperimentMetric($key, $byVariant, self::clear($variantNames, $byVariant, $counts), array_values($parts));
        };
        $bootstrap = static fn (Metric $of, string $key, callable $value): \Closure => static function (string $name) use ($of, $key, $value, $merged, $experiment): array {
            $values = array_values(array_filter(array_map($value, $merged[$name]), static fn (int|float|null $item): bool => null !== $item));

            return [self::interval($of, $experiment.':'.$key.':'.$name, $values), \count($values)];
        };

        $metrics = [];
        foreach ($keys as $key) {
            $of = Metric::from($key);
            $metrics[$key] = match ($of) {
                Metric::MergeRate => $metric($key, static function (string $name) use ($kept, $finished, $experiment, $key): array {
                    $values = array_map(static fn (array $row): int => $row['outcome']->merged ? 1 : 0, array_values(array_filter($kept[$name] ?? [], static fn (array $row): bool => $row['finished'])));

                    return [self::interval(Metric::MergeRate, $experiment.':'.$key.':'.$name, $values), $finished[$name]];
                }),
                Metric::StopRate => $metric($key, static function (string $name) use ($kept, $finished, $experiment, $key): array {
                    $closed = array_filter(array_merge(...array_column($kept[$name] ?? [], 'runs')), static fn (WorkerRun $run): bool => $run->state->isOutcome());
                    $values = array_values(array_map(static fn (WorkerRun $run): int => $run->state->isStop() ? 1 : 0, $closed));

                    return [self::interval(Metric::StopRate, $experiment.':'.$key.':'.$name, $values), $finished[$name]];
                }),
                Metric::FixRounds => $metric(
                    $key,
                    $bootstrap($of, $key, static fn (array $row): int => $row['outcome']->totalFixRounds()),
                    array_map(
                        static fn (string $reason): ExperimentMetric => $metric($reason, $bootstrap($of, $key.':'.$reason, static fn (array $row): int => $row['outcome']->fixRounds[$reason] ?? 0)),
                        $reasons,
                    ),
                ),
                Metric::HoursToMerge => $metric($key, $bootstrap($of, $key, static fn (array $row): ?float => $row['outcome']->hoursToMerge())),
                Metric::Runs => $metric($key, $bootstrap($of, $key, static fn (array $row): int => \count($row['runs']))),
                Metric::Cost => $metric($key, $bootstrap($of, $key, static fn (array $row): ?float => null === $row['costMicros'] ? null : $row['costMicros'] / 1_000_000)),
                Metric::InputTokens, Metric::OutputTokens, Metric::CacheReadTokens, Metric::CacheWriteTokens, Metric::Duration => $metric($key, $bootstrap($of, $key, static fn (array $row): ?int => MetricRowSource::cardSum($row['facts'], $of))),
            };
        }

        return $metrics;
    }

    /**
     * A ratio counts successes, so it takes a Wilson range. Any other value takes a bootstrap of its mean.
     *
     * @param list<int|float> $values
     */
    private static function interval(Metric $metric, string $seed, array $values): ?Interval
    {
        return MetricValueType::Ratio === $metric->valueType()
            ? Stats::wilson((int) array_sum($values), \count($values))
            : Stats::bootstrapMean($values, $seed);
    }

    /**
     * @param list<string>             $variantNames
     * @param array<string, ?Interval> $byVariant
     * @param array<string, int>       $counts       the cards behind each range
     */
    private static function clear(array $variantNames, array $byVariant, array $counts): bool
    {
        if (\count($variantNames) < 2) {
            return false;
        }
        [$a, $b] = $variantNames;
        $intervalA = $byVariant[$a] ?? null;
        $intervalB = $byVariant[$b] ?? null;

        return null !== $intervalA && null !== $intervalB && Stats::isClear($intervalA, $intervalB, $counts[$a] ?? 0, $counts[$b] ?? 0);
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
