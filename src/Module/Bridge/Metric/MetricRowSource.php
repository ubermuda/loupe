<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Experiment\CardOutcome;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Turns the fact rows and the finished cards into one metric row per run or per card. */
final readonly class MetricRowSource
{
    public function __construct(
        private WorkerRunFactRepository $workerRunFacts,
        private FinishedCardSourceInterface $finishedCards,
        private CardReportSourceInterface $cardReports,
    ) {
    }

    /** @return list<MetricRow> */
    public function rows(Project $project, MetricUnit $unit, Metric $metric, MetricGroup $group, ?\DateTimeImmutable $from): array
    {
        return MetricUnit::Run === $unit
            ? $this->runRows($project, $metric, $group, $from)
            : $this->cardRows($project, $metric, $group, $from);
    }

    /** @return list<MetricRow> */
    private function runRows(Project $project, Metric $metric, MetricGroup $group, ?\DateTimeImmutable $from): array
    {
        // A stage belongs to the card workflow, so a stage grouping reads card runs alone.
        $facts = $this->workerRunFacts->findClosedSince($project, $from, MetricGroup::Stage === $group);
        $types = MetricGroup::CardType === $group ? $this->cardReports->typesFor($project, self::cardIdsOf($facts)) : [];

        return array_map(
            static fn (WorkerRunFact $fact): MetricRow => new MetricRow(
                $fact->runId,
                $fact->cardNumber,
                self::groupOf($fact, $group, $types),
                $fact->endedAt ?? $fact->receivedAt,
                self::runValue($fact, $metric),
            ),
            $facts,
        );
    }

    /** @return list<MetricRow> */
    private function cardRows(Project $project, Metric $metric, MetricGroup $group, ?\DateTimeImmutable $from): array
    {
        $cards = $this->finishedCards->finishedCards($project, $from);
        if ([] === $cards) {
            return [];
        }

        $ids = array_map(static fn (FinishedCard $card): Uuid => $card->id, $cards);
        $factsByCard = [];
        if (!$metric->isCardOutcome() || $group->isRunGrouping()) {
            foreach ($this->workerRunFacts->findOfCards($project, $ids) as $fact) {
                $factsByCard[(string) $fact->subjectId][] = $fact;
            }
        }
        $outcomes = $metric->isCardOutcome() ? $this->cardReports->outcomesFor($project, $ids) : [];
        $types = MetricGroup::CardType === $group ? $this->cardReports->typesFor($project, $ids) : [];

        $rows = [];
        foreach ($cards as $card) {
            $id = (string) $card->id;
            $facts = $factsByCard[$id] ?? [];
            /** @var array<string, array{group: ?string, facts: list<WorkerRunFact>}> $partitions */
            $partitions = [];
            if ($group->isRunGrouping() && [] !== $facts) {
                foreach ($facts as $fact) {
                    $key = self::groupOf($fact, $group, $types);
                    $partitions[null === $key ? '' : 'key:'.$key]['group'] = $key;
                    $partitions[null === $key ? '' : 'key:'.$key]['facts'][] = $fact;
                }
            } else {
                $partitions[] = ['group' => $group->isRunGrouping() ? null : $types[$id] ?? null, 'facts' => $facts];
            }

            foreach ($partitions as $partition) {
                $rows[] = new MetricRow(
                    $card->id,
                    $card->number,
                    $partition['group'],
                    $card->completedAt,
                    $metric->isCardOutcome()
                        ? self::outcomeValue($outcomes[$id] ?? new CardOutcome(), $metric)
                        : self::cardValue($partition['facts'], $metric),
                );
            }
        }

        return $rows;
    }

    /**
     * @param list<WorkerRunFact> $facts
     *
     * @return list<Uuid>
     */
    private static function cardIdsOf(array $facts): array
    {
        $ids = [];
        foreach ($facts as $fact) {
            if ('card' === $fact->subjectType) {
                $ids[(string) $fact->subjectId] = $fact->subjectId;
            }
        }

        return array_values($ids);
    }

    /** @param array<string, string> $types card id => card type */
    private static function groupOf(WorkerRunFact $fact, MetricGroup $group, array $types): ?string
    {
        return match ($group) {
            MetricGroup::Stage => $fact->workKind,
            MetricGroup::Model => $fact->model,
            MetricGroup::Variant => $fact->variant,
            MetricGroup::Bridge => $fact->bridgeId?->toRfc4122(),
            MetricGroup::CardType => 'card' === $fact->subjectType ? $types[(string) $fact->subjectId] ?? null : null,
            MetricGroup::None => null,
        };
    }

    private static function runValue(WorkerRunFact $fact, Metric $metric): int|float|null
    {
        return self::inDollars(self::rawValue($fact, $metric), $metric);
    }

    /** Cost stays in micro-dollars here, so a sum adds integers. */
    private static function rawValue(WorkerRunFact $fact, Metric $metric): ?int
    {
        return match ($metric) {
            Metric::Cost => $fact->costMicroUsd,
            Metric::InputTokens => $fact->tokensIn,
            Metric::OutputTokens => $fact->tokensOut,
            Metric::CacheReadTokens => $fact->tokensCacheRead,
            Metric::CacheWriteTokens => $fact->tokensCacheWrite,
            Metric::Duration => $fact->durationMs,
            Metric::StopRate => $fact->outcome->isStop() ? 1 : 0,
            Metric::Runs, Metric::MergeRate, Metric::FixRounds, Metric::HoursToMerge => throw new \LogicException(\sprintf('The metric %s has no run value.', $metric->value)),
        };
    }

    /**
     * The sum over the runs of a card. A run that started with no value makes
     * the sum unknown, and so does a run with usage and no cost or tokens.
     * Null when no run gives a value.
     *
     * @param list<WorkerRunFact> $facts
     */
    private static function cardValue(array $facts, Metric $metric): int|float|null
    {
        if (Metric::Runs === $metric) {
            return \count($facts);
        }

        $sum = null;
        foreach ($facts as $fact) {
            $value = self::rawValue($fact, $metric);
            if (null === $value) {
                $counts = null !== $fact->startedAt || (Metric::Duration !== $metric && null !== $fact->usageSource);
                if ($counts) {
                    return null;
                }
                continue;
            }
            $sum = ($sum ?? 0) + $value;
        }

        return self::inDollars($sum, $metric);
    }

    private static function inDollars(?int $value, Metric $metric): int|float|null
    {
        return Metric::Cost === $metric && null !== $value ? $value / 1_000_000.0 : $value;
    }

    private static function outcomeValue(CardOutcome $outcome, Metric $metric): int|float|null
    {
        return match ($metric) {
            Metric::MergeRate => $outcome->merged ? 1 : 0,
            Metric::FixRounds => $outcome->totalFixRounds(),
            Metric::HoursToMerge => $outcome->hoursToMerge(),
            default => throw new \LogicException(\sprintf('The metric %s is no card outcome.', $metric->value)),
        };
    }
}
