<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Command\MetricQueryView;
use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Experiment\CardOutcome;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class MetricQueryHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-05 12:00:00';

    private EntityManagerInterface $em;
    private Project $project;

    /** @var list<FinishedCard> */
    private array $finished = [];

    /** @var array<string, string> */
    private array $types = [];

    /** @var array<string, CardOutcome> */
    private array $outcomes = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
        $owner = $this->user($this->em, 'metric-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $owner, 'Metrics');
    }

    public function test_a_run_query_reads_the_closed_runs_in_the_range_by_bucket(): void
    {
        $card = Uuid::v7();
        $this->fact($card, endedAt: '2026-10-01 09:00:00', cost: 1_000_000, cardNumber: 4);
        $this->fact($card, endedAt: '2026-10-01 17:00:00', cost: 3_000_000);
        $late = $this->fact($card, endedAt: '2026-10-03 08:00:00', cost: 500_000);
        $this->fact($card, endedAt: '2026-10-03 07:00:00', cost: null, startedAt: '2026-10-03 06:00:00');
        $this->fact($card, endedAt: '2026-08-01 09:00:00', cost: 9_000_000);
        $this->fact($card, endedAt: null, cost: 2_000_000, receivedAt: '2026-10-04 09:00:00');

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum);

        self::assertCount(1, $view->series);
        $series = $view->series[0];
        self::assertNull($series->group);
        self::assertEquals([
            new MetricPoint(new \DateTimeImmutable('2026-10-01 00:00:00 UTC'), 4.0, 2),
            new MetricPoint(new \DateTimeImmutable('2026-10-03 00:00:00 UTC'), 0.5, 1),
            new MetricPoint(new \DateTimeImmutable('2026-10-04 00:00:00 UTC'), 2.0, 1),
        ], $series->points);
        self::assertEquals(new MetricPoint(null, 6.5, 4), $series->total);
        self::assertCount(5, $series->rows);
        self::assertTrue($late->equals($series->rows[1]->unitId));
        self::assertSame([2.0, 0.5, null, 3.0, 1.0], array_map(static fn (MetricRow $row): int|float|null => $row->value, $series->rows));
        self::assertSame(4, $series->rows[4]->cardNumber);
    }

    public function test_a_run_query_leaves_an_open_run_out(): void
    {
        $this->fact(Uuid::v7(), endedAt: '2026-10-01 09:00:00', cost: 1_000_000);
        $this->fact(Uuid::v7(), endedAt: null, cost: 7_000_000, receivedAt: '2026-10-01 09:00:00', outcome: 'running');
        $this->fact(Uuid::v7(), endedAt: null, cost: 7_000_000, receivedAt: '2026-10-01 09:00:00', outcome: 'queued');

        $view = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Count);

        self::assertEquals(new MetricPoint(null, 1, 1), $view->series[0]->total);
    }

    public function test_the_stop_rate_counts_a_stop_as_one_and_any_other_closed_run_as_zero(): void
    {
        foreach (['blocked', 'failed', 'succeeded', 'stopped'] as $outcome) {
            $this->fact(Uuid::v7(), endedAt: '2026-10-01 09:00:00', outcome: $outcome);
        }

        $view = $this->query(MetricUnit::Run, Metric::StopRate, MetricStatistic::Mean);

        self::assertEquals(new MetricPoint(null, 0.5, 4), $view->series[0]->total);
    }

    public function test_a_card_query_grouped_by_stage_gives_one_series_per_work_kind(): void
    {
        $first = $this->finishedCard(1, '2026-10-02 10:00:00');
        $second = $this->finishedCard(2, '2026-10-03 10:00:00');
        $this->finishedCard(3, '2026-08-01 10:00:00');
        $this->fact($first, endedAt: '2026-09-01 09:00:00', cost: 1_000_000, workKind: 'implement');
        $this->fact($first, endedAt: '2026-09-02 09:00:00', cost: 500_000, workKind: 'implement');
        $this->fact($first, endedAt: '2026-09-03 09:00:00', cost: 2_000_000, workKind: 'review');
        $this->fact($second, endedAt: '2026-10-03 09:00:00', cost: 4_000_000, workKind: 'implement');

        $view = $this->query(MetricUnit::Card, Metric::Cost, MetricStatistic::Sum, MetricGroup::Stage);

        self::assertSame(['implement', 'review'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        [$implement, $review] = $view->series;
        self::assertEquals(new MetricPoint(null, 5.5, 2), $implement->total);
        self::assertSame([4.0, 1.5], array_map(static fn (MetricRow $row): int|float|null => $row->value, $implement->rows));
        self::assertTrue($second->equals($implement->rows[0]->unitId));
        self::assertSame(2, $implement->rows[0]->cardNumber);
        self::assertEquals(new \DateTimeImmutable('2026-10-03 10:00:00'), $implement->rows[0]->time);
        self::assertEquals(new MetricPoint(null, 2.0, 1), $review->total);
    }

    public function test_a_card_with_a_started_run_and_no_cost_has_an_unknown_cost(): void
    {
        $priced = $this->finishedCard(1, '2026-10-02 10:00:00');
        $partial = $this->finishedCard(2, '2026-10-03 10:00:00');
        $this->fact($priced, endedAt: '2026-10-01 09:00:00', cost: 1_000_000);
        $this->fact($priced, endedAt: null, cost: null, startedAt: null, receivedAt: '2026-10-01 09:00:00', outcome: 'stopped');
        $this->fact($partial, endedAt: '2026-10-01 09:00:00', cost: 1_000_000);
        $this->fact($partial, endedAt: '2026-10-01 10:00:00', cost: null, startedAt: '2026-10-01 09:30:00');

        $view = $this->query(MetricUnit::Card, Metric::Cost, MetricStatistic::Sum);

        self::assertSame([null, 1.0], array_map(static fn (MetricRow $row): int|float|null => $row->value, $view->series[0]->rows));
        self::assertEquals(new MetricPoint(null, 1.0, 1), $view->series[0]->total);
    }

    public function test_a_card_query_grouped_by_card_type_reads_the_types_from_the_board(): void
    {
        $bug = $this->finishedCard(1, '2026-10-02 10:00:00');
        $feature = $this->finishedCard(2, '2026-10-03 10:00:00');
        $this->types = [(string) $bug => 'bug', (string) $feature => 'feature'];
        foreach ([$bug, $bug, $bug, $feature] as $card) {
            $this->fact($card, endedAt: '2026-10-01 09:00:00');
        }

        $view = $this->query(MetricUnit::Card, Metric::Runs, MetricStatistic::Sum, MetricGroup::CardType);

        self::assertSame(['bug', 'feature'], array_map(static fn (MetricSeries $series): ?string => $series->group, $view->series));
        self::assertEquals(new MetricPoint(null, 3, 1), $view->series[0]->total);
        self::assertEquals(new MetricPoint(null, 1, 1), $view->series[1]->total);
    }

    public function test_the_card_outcomes_give_the_merge_rate_and_the_fix_rounds(): void
    {
        $merged = $this->finishedCard(1, '2026-10-02 10:00:00');
        $this->finishedCard(2, '2026-10-03 10:00:00');
        $this->outcomes = [(string) $merged => new CardOutcome(fixRounds: ['conflict' => 2, 'review' => 1], merged: true)];

        self::assertEquals(new MetricPoint(null, 0.5, 2), $this->query(MetricUnit::Card, Metric::MergeRate, MetricStatistic::Mean)->series[0]->total);
        self::assertEquals(new MetricPoint(null, 3, 2), $this->query(MetricUnit::Card, Metric::FixRounds, MetricStatistic::Sum)->series[0]->total);
        self::assertEquals(new MetricPoint(null, null, 0), $this->query(MetricUnit::Card, Metric::HoursToMerge, MetricStatistic::Mean)->series[0]->total);
    }

    public function test_a_run_of_another_subject_counts_only_where_no_card_is_needed(): void
    {
        $card = $this->finishedCard(1, '2026-10-02 10:00:00');
        $this->fact($card, endedAt: '2026-10-01 09:00:00', cost: 1_000_000, workKind: 'implement');
        $this->fact($card, endedAt: '2026-10-01 10:00:00', cost: 7_000_000, workKind: 'implement', subjectType: 'analysis');

        self::assertEquals(new MetricPoint(null, 8.0, 2), $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum)->series[0]->total);
        $byStage = $this->query(MetricUnit::Run, Metric::Cost, MetricStatistic::Sum, MetricGroup::Stage);
        self::assertCount(1, $byStage->series);
        self::assertEquals(new MetricPoint(null, 1.0, 1), $byStage->series[0]->total);
        self::assertEquals(new MetricPoint(null, 1.0, 1), $this->query(MetricUnit::Card, Metric::Cost, MetricStatistic::Sum)->series[0]->total);
    }

    /** @return iterable<string, array{MetricUnit, Metric, MetricStatistic, MetricGroup, string}> */
    public static function refusals(): iterable
    {
        yield 'a card metric on the run unit' => [MetricUnit::Run, Metric::Runs, MetricStatistic::Sum, MetricGroup::None, 'unit'];
        yield 'the stop rate on the card unit' => [MetricUnit::Card, Metric::StopRate, MetricStatistic::Mean, MetricGroup::None, 'unit'];
        yield 'a percentile of a ratio' => [MetricUnit::Run, Metric::StopRate, MetricStatistic::P90, MetricGroup::None, 'statistic'];
        yield 'a sum of a ratio' => [MetricUnit::Card, Metric::MergeRate, MetricStatistic::Sum, MetricGroup::None, 'statistic'];
        yield 'a card outcome by stage' => [MetricUnit::Card, Metric::MergeRate, MetricStatistic::Mean, MetricGroup::Stage, 'group'];
        yield 'fix rounds by model' => [MetricUnit::Card, Metric::FixRounds, MetricStatistic::Sum, MetricGroup::Model, 'group'];
        yield 'hours to merge by bridge' => [MetricUnit::Card, Metric::HoursToMerge, MetricStatistic::Median, MetricGroup::Bridge, 'group'];
    }

    #[DataProvider('refusals')]
    public function test_it_refuses_a_combination_the_metric_does_not_allow(MetricUnit $unit, Metric $metric, MetricStatistic $statistic, MetricGroup $group, string $field): void
    {
        try {
            $this->query($unit, $metric, $statistic, $group);
            self::fail('The query was not refused.');
        } catch (DomainErrors $errors) {
            self::assertSame([$field], array_keys($errors->errors));
        }
    }

    private function query(MetricUnit $unit, Metric $metric, MetricStatistic $statistic, MetricGroup $group = MetricGroup::None): MetricQueryView
    {
        $finished = new readonly class($this->finished) implements FinishedCardSourceInterface {
            /** @param list<FinishedCard> $cards */
            public function __construct(
                private array $cards,
            ) {
            }

            #[\Override]
            public function finishedCards(Project $project, ?\DateTimeImmutable $completedSince): array
            {
                return array_values(array_filter($this->cards, static fn (FinishedCard $card): bool => null === $completedSince || $card->completedAt >= $completedSince));
            }
        };
        $reports = new readonly class($this->types, $this->outcomes) implements CardReportSourceInterface {
            /**
             * @param array<string, string>      $types
             * @param array<string, CardOutcome> $outcomes
             */
            public function __construct(
                private array $types,
                private array $outcomes,
            ) {
            }

            #[\Override]
            public function columnsFor(Project $project, array $cardIds): array
            {
                return [];
            }

            #[\Override]
            public function outcomesFor(Project $project, array $cardIds): array
            {
                return $this->outcomes;
            }

            #[\Override]
            public function typesFor(Project $project, array $cardIds): array
            {
                return $this->types;
            }

            #[\Override]
            public function historyStartFor(Project $project): ?\DateTimeImmutable
            {
                return null;
            }
        };
        $facts = self::getContainer()->get(WorkerRunFactRepository::class);
        self::assertInstanceOf(WorkerRunFactRepository::class, $facts);
        $handler = new MetricQueryHandler(new MetricRowSource($facts, $finished, $reports), new MockClock(self::NOW.' UTC'));

        return $handler(new MetricQueryCommand($this->project, $unit, $metric, $statistic, $group, MetricRange::ThirtyDays, MetricBucket::Day));
    }

    private function finishedCard(int $number, string $completedAt): Uuid
    {
        $id = Uuid::v7();
        $this->finished[] = new FinishedCard($id, $number, 'Card '.$number, new \DateTimeImmutable($completedAt));

        return $id;
    }

    private function fact(
        Uuid $subjectId,
        ?string $endedAt,
        ?int $cost = 0,
        ?string $startedAt = '2026-09-01 08:00:00',
        ?string $receivedAt = null,
        string $outcome = 'succeeded',
        ?string $workKind = 'implement',
        string $subjectType = 'card',
        ?int $cardNumber = null,
    ): Uuid {
        $runId = Uuid::v7();
        $this->em->getConnection()->insert('bridge_worker_run_facts', [
            'run_id' => (string) $runId,
            'project_id' => (string) $this->project->id,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'card_number' => $cardNumber,
            'kind' => 'worker',
            'work_kind' => $workKind,
            'outcome' => $outcome,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'received_at' => $receivedAt ?? $endedAt,
            'cost_micro_usd' => $cost,
            'usage_source' => null === $cost ? null : 'reported',
        ]);

        return $runId;
    }
}
