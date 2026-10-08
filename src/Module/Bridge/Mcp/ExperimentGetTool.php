<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\LeftOutReason;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Metric\Metric;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads the comparison of one experiment, and one page of its cards.
 *
 * @phpstan-type ExperimentRange array{point: float, low: float, high: float}
 * @phpstan-type ExperimentMetricRow array{key: string, valueType: string, clear: bool, byVariant: array<string, ExperimentRange|null>, parts: list<mixed>}
 * @phpstan-type ExperimentCardRow array{cardId: string, number: ?int, title: ?string, variant: ?string, column: ?string, runs: int, fixRounds: int, costUsd: ?float, leftOut: list<string>}
 */
#[McpTool(name: self::NAME, description: 'Read the comparison of one experiment of this project, as its Comparison and Cards tabs show it. Pass experiment, the name of the experiment. The response has experiment, declaredMetrics (the metric keys the rule declares, in their order, or null when it declares none and the default metrics apply), unknownMetrics (the declared keys that no metric has), minFinishedCards, headline, variants, metrics, includedCards, leftOutCards and cards. A money value is in US dollars, a duration in milliseconds, hours-to-merge in hours, and a ratio is between 0 and 1. The figures compare the first two variants by name. headline has cheaperVariant (the variant with the lower cost per merged card, or null when the two have no cost each), saving (the share of the cost per merged card of the dearer variant that the cheaper one saves, or null), costClear (the cost gives a clear answer) and qualitySettled (the merge rate and the fix rounds both give a clear answer). Each variant has name, model (the model its latest run asked for), weight (null when the bridge sent none), cards and finishedCards (the kept cards, and those that merged or stand in a terminal column), runs and costUsd (null when no kept card reported usage). Each metric has key, valueType, clear, byVariant and parts. byVariant maps each variant to point, low and high, the value and its 95 percent range, or to null when the variant has no data. A metric is clear when each of the first two variants has at least minFinishedCards cards behind its range, and their ranges do not overlap or their points are within 10 percent of the larger one. A rate counts the finished cards, and a value per merged card counts the merged cards that have the value. parts has the same shape, one row per fix reason under fix-rounds, with the value type of its metric. includedCards and leftOutCards count the cards the figures keep and leave out. cards lists one page of cards, the latest run first, each with cardId, number, title, variant, column, runs, fixRounds, costUsd and leftOut. leftOut is empty for a kept card, else its reasons: switched (a run moved the card from a variant the rule no longer offers), mixed (its runs and its pin name more than one variant), before-test (a run with no experiment did the same kind of work on the card first), no-history (the card history starts after its first experiment run) and no-run (a pin names the card and no experiment run worked it). Filter the cards with variant, which keeps the kept cards of one variant, or with leftOutOnly. The cards are paginated: pass page to walk further, and keep going while hasMore is true. total counts the cards that match the filters. An experiment with no run and no pin in this project is refused.')]
final readonly class ExperimentGetTool
{
    public const string NAME = 'experiment_get';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ShowExperimentHandler $showExperiment,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string      $experiment  the name of the experiment, as worker_run_list and metric_query name it
     * @param int         $page        the 1-based page of the cards to read
     * @param string|null $variant     only the kept cards of this variant
     * @param bool        $leftOutOnly only the cards the figures leave out
     *
     * @return array{experiment: string, declaredMetrics: list<string>|null, unknownMetrics: list<string>, minFinishedCards: int, headline: array{cheaperVariant: ?string, saving: ?float, costClear: bool, qualitySettled: bool}, variants: list<array{name: string, model: ?string, weight: ?int, cards: int, finishedCards: int, runs: int, costUsd: ?float}>, metrics: list<ExperimentMetricRow>, includedCards: int, leftOutCards: int, cards: list<ExperimentCardRow>, page: int, totalPages: int, total: int, hasMore: bool}
     */
    public function __invoke(string $experiment, #[Schema(minimum: 1)] int $page = 1, ?string $variant = null, bool $leftOutOnly = false): array
    {
        try {
            $report = ($this->showExperiment)(new ShowExperimentCommand(
                $this->subjects->requireReadableProject(),
                $experiment,
                $page,
                $variant,
                $leftOutOnly,
            )) ?? throw new ToolCallException(\sprintf('No experiment named "%s" exists in this project. worker_run_list names the experiment of each run.', $experiment));
            $headline = $report->headline ?? throw new \LogicException('A report with metrics has a headline.');

            return [
                'experiment' => $report->experiment,
                'declaredMetrics' => $report->declaredMetrics,
                'unknownMetrics' => $report->unknownMetrics,
                'minFinishedCards' => Stats::MIN_FINISHED_CARDS,
                'headline' => [
                    'cheaperVariant' => $headline->cheaperVariant,
                    'saving' => $headline->saving,
                    'costClear' => $headline->costClear,
                    'qualitySettled' => $headline->qualitySettled,
                ],
                'variants' => array_map(static fn (ExperimentVariant $variant): array => [
                    'name' => $variant->name,
                    'model' => $variant->model,
                    'weight' => $variant->weight,
                    'cards' => $variant->cards,
                    'finishedCards' => $variant->finishedCards,
                    'runs' => $variant->runs,
                    'costUsd' => self::usd($variant->costMicros),
                ], $report->variants),
                'metrics' => array_values(array_map(
                    static fn (ExperimentMetric $metric): array => self::metric($metric, Metric::from($metric->key)->valueType()->value),
                    $report->metrics,
                )),
                'includedCards' => $report->includedCards,
                'leftOutCards' => $report->leftOutCards,
                'cards' => array_map($this->card(...), $report->cards),
                'page' => $report->page,
                'totalPages' => $report->totalPages,
                'total' => $report->filteredTotal,
                'hasMore' => $report->page * ShowExperimentHandler::PER_PAGE < $report->filteredTotal,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The experiment could not be read. The error has been logged.', previous: $e);
        }
    }

    /** @return ExperimentMetricRow */
    private static function metric(ExperimentMetric $metric, string $valueType): array
    {
        return [
            'key' => $metric->key,
            'valueType' => $valueType,
            'clear' => $metric->clear,
            'byVariant' => array_map(
                static fn (?Interval $interval): ?array => null === $interval ? null : ['point' => $interval->point, 'low' => $interval->low, 'high' => $interval->high],
                $metric->byVariant,
            ),
            'parts' => array_map(static fn (ExperimentMetric $part): array => self::metric($part, $valueType), $metric->parts),
        ];
    }

    /** @return ExperimentCardRow */
    private function card(ExperimentCard $card): array
    {
        return [
            'cardId' => $card->cardId->toRfc4122(),
            'number' => $card->number,
            'title' => $card->title,
            'variant' => $card->variant,
            'column' => null === $card->column ? null : $this->translator->trans($card->column->label),
            'runs' => $card->runs,
            'fixRounds' => $card->fixRounds,
            'costUsd' => self::usd($card->costMicros),
            'leftOut' => array_map(static fn (LeftOutReason $reason): string => $reason->value, $card->leftOut),
        ];
    }

    private static function usd(?int $micros): ?float
    {
        return null === $micros ? null : $micros / 1_000_000;
    }
}
