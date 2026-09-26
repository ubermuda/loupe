<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ShowWorkerRunCostCommand;
use App\Module\Bridge\Command\ShowWorkerRunCostHandler;
use App\Module\Bridge\Command\WorkerRunCostView;
use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\CostGroup;
use App\Module\Bridge\ValueObject\CostRange;
use App\Module\Bridge\ValueObject\CostSplit;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\View\CardCost;
use App\Module\Bridge\View\CostPart;
use App\Module\Bridge\View\WorkerRunCostQuery;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ShowWorkerRunCostHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-25 12:00:00';

    private Project $project;
    private FinishedCard $first;
    private FinishedCard $second;
    private FinishedCard $third;
    private FinishedCard $noUsage;

    /** @var list<?\DateTimeImmutable> */
    private array $askedSince = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'cost-'.uniqid().'@example.com');
        $this->project = $this->project($em, $owner, 'Cost');
        $other = $this->project($em, $owner, 'Other cost');

        $this->first = new FinishedCard(Uuid::v7(), 1, 'First', new \DateTimeImmutable('2026-09-01 10:00:00'));
        $this->second = new FinishedCard(Uuid::v7(), 2, 'Second', new \DateTimeImmutable('2026-09-10 10:00:00'));
        $this->third = new FinishedCard(Uuid::v7(), 3, 'Third', new \DateTimeImmutable('2026-09-20 10:00:00'));
        $this->noUsage = new FinishedCard(Uuid::v7(), 4, 'No usage', new \DateTimeImmutable('2026-09-21 10:00:00'));

        $this->seedUsage($em, $this->seedRun($em, $this->project, ruleName: 'plan', cardId: $this->first->id), model: 'claude-opus-5-5', costUsd: '1.000000');
        $this->seedUsage($em, $this->seedRun($em, $this->project, ruleName: 'build', cardId: $this->first->id), model: 'claude-sonnet-5', costUsd: '0.500000');
        // Started, closed, and reported nothing.
        $this->seedRun($em, $this->project, ruleName: 'build', cardId: $this->first->id);
        // Still running, so it is not partial yet.
        $this->seedRun($em, $this->project, ruleName: 'build', cardId: $this->first->id, state: WorkerRunState::Running);
        $this->seedRun($em, $this->project, ruleName: 'plan', cardId: $this->first->id, kind: WorkerRunKind::Interactive);

        $this->seedUsage($em, $this->seedRun($em, $this->project, ruleName: 'build', cardId: $this->second->id), model: 'claude-sonnet-5', source: WorkerRunUsageSource::Estimated, costUsd: '0.250000');

        $this->seedUsage($em, $this->seedRun($em, $this->project, ruleName: 'plan', cardId: $this->third->id), model: 'claude-opus-5-5', costUsd: '2.000000');
        $this->seedUsage($em, $this->seedRun($em, $this->project, ruleName: 'plan', cardId: $this->third->id), model: 'unpriced-model', costUsd: null);

        $this->seedRun($em, $this->project, ruleName: 'plan', cardId: $this->noUsage->id);
        // Another project's spend on the same card id stays out.
        $this->seedUsage($em, $this->seedRun($em, $other, ruleName: 'plan', cardId: $this->first->id), costUsd: '9.000000');
    }

    public function test_it_sums_the_usage_of_each_finished_card_with_usage(): void
    {
        $view = $this->show(new WorkerRunCostQuery());

        self::assertSame(['First', 'Second', 'Third'], $this->titles($view));
        self::assertSame([1_500_000, 250_000, 2_000_000], array_map(static fn (CardCost $cost): int => $cost->costMicros, $view->cards));
        self::assertSame(3_750_000, $view->totalMicros);
        self::assertSame(1_500_000, $view->medianMicros);
        self::assertSame(['build', 'plan'], $view->rules);
        self::assertSame(['claude-opus-5-5', 'claude-sonnet-5', 'unpriced-model'], $view->models);
        self::assertSame(200, $view->cards[0]->inputTokens);
        self::assertNotNull($view->chart);
    }

    public function test_the_median_of_an_even_count_is_the_mean_of_the_middle_pair(): void
    {
        $view = $this->show(new WorkerRunCostQuery(split: CostSplit::None), [$this->first, $this->second]);

        self::assertSame(875_000, $view->medianMicros);
    }

    public function test_the_range_asks_for_the_cards_finished_since_its_start(): void
    {
        $this->show(new WorkerRunCostQuery(range: CostRange::ThirtyDays));
        $this->show(new WorkerRunCostQuery());
        $this->show(new WorkerRunCostQuery(range: CostRange::AllTime));

        self::assertEquals([
            new \DateTimeImmutable('2026-08-26 12:00:00'),
            new \DateTimeImmutable('2026-06-27 12:00:00'),
            null,
        ], $this->askedSince);
    }

    public function test_a_split_by_rule_or_by_model_gives_one_part_per_key(): void
    {
        $byRule = $this->show(new WorkerRunCostQuery(split: CostSplit::Rule));
        $byModel = $this->show(new WorkerRunCostQuery(split: CostSplit::Model));

        self::assertSame(['build' => 500_000, 'plan' => 1_000_000], $this->parts($byRule->cards[0]));
        self::assertSame(['claude-opus-5-5' => 1_000_000, 'claude-sonnet-5' => 500_000], $this->parts($byModel->cards[0]));
        self::assertSame(['claude-opus-5-5' => 2_000_000, 'unpriced-model' => 0], $this->parts($byModel->cards[2]));
    }

    public function test_a_rule_filter_keeps_only_the_matching_part_and_the_figures_follow(): void
    {
        $view = $this->show(new WorkerRunCostQuery(rule: 'plan'));

        self::assertSame(['First', 'Third'], $this->titles($view));
        self::assertSame(1_000_000, $view->cards[0]->costMicros);
        self::assertSame(3_000_000, $view->totalMicros);
        self::assertSame(1_500_000, $view->medianMicros);
        // The partial run of the first card belongs to another rule.
        self::assertSame(0, $view->cards[0]->partialRuns);
    }

    public function test_a_model_filter_keeps_only_the_matching_part(): void
    {
        $view = $this->show(new WorkerRunCostQuery(model: 'claude-sonnet-5'));

        self::assertSame(['First', 'Second'], $this->titles($view));
        self::assertSame([500_000, 250_000], array_map(static fn (CardCost $cost): int => $cost->costMicros, $view->cards));
    }

    public function test_a_card_carries_its_partial_runs_and_its_estimated_parts(): void
    {
        $view = $this->show(new WorkerRunCostQuery());

        self::assertSame([1, 0, 0], array_map(static fn (CardCost $cost): int => $cost->partialRuns, $view->cards));
        // An estimate, and a row with no price.
        self::assertSame([false, true, true], array_map(static fn (CardCost $cost): bool => $cost->estimated, $view->cards));
    }

    public function test_the_chart_groups_by_the_default_of_the_range_or_the_chosen_group_and_draws_the_median(): void
    {
        $default = $this->show(new WorkerRunCostQuery(range: CostRange::ThirtyDays));
        $chosen = $this->show(new WorkerRunCostQuery(range: CostRange::ThirtyDays, group: CostGroup::Month));

        self::assertNotNull($default->chart);
        self::assertNotNull($chosen->chart);
        self::assertSame(CostGroup::Day, $default->chart->group);
        self::assertSame(CostGroup::Month, $chosen->chart->group);
        self::assertSame($default->medianMicros, $default->chart->medianMicros);
    }

    public function test_no_finished_card_with_usage_gives_no_chart(): void
    {
        $view = $this->show(new WorkerRunCostQuery(), [$this->noUsage]);

        self::assertSame([], $view->cards);
        self::assertSame(0, $view->totalMicros);
        self::assertSame(0, $view->medianMicros);
        self::assertNull($view->chart);
    }

    /** @param list<FinishedCard>|null $finished */
    private function show(WorkerRunCostQuery $query, ?array $finished = null): WorkerRunCostView
    {
        $finished ??= [$this->first, $this->second, $this->third, $this->noUsage];
        $record = function (?\DateTimeImmutable $since): void {
            $this->askedSince[] = $since;
        };
        $source = new readonly class($finished, $record) implements FinishedCardSourceInterface {
            /**
             * @param list<FinishedCard>                  $finished
             * @param \Closure(?\DateTimeImmutable): void $record
             */
            public function __construct(
                private array $finished,
                private \Closure $record,
            ) {
            }

            #[\Override]
            public function finishedCards(Project $project, ?\DateTimeImmutable $completedSince): array
            {
                ($this->record)($completedSince);

                return $this->finished;
            }
        };

        $handler = new ShowWorkerRunCostHandler(
            $source,
            self::getContainer()->get(WorkerRunUsageRepository::class),
            self::getContainer()->get(WorkerRunRepository::class),
            new MockClock(self::NOW),
        );

        return $handler(new ShowWorkerRunCostCommand($this->project, $query));
    }

    /** @return list<string> */
    private function titles(WorkerRunCostView $view): array
    {
        return array_map(static fn (CardCost $cost): string => $cost->card->title, $view->cards);
    }

    /** @return array<string, int> */
    private function parts(CardCost $cost): array
    {
        return array_combine(
            array_map(static fn (CostPart $part): string => $part->key, $cost->parts),
            array_map(static fn (CostPart $part): int => $part->costMicros, $cost->parts),
        );
    }
}
