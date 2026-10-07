<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ExperimentReportView;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Experiment\CardColumn;
use App\Module\Bridge\Experiment\CardOutcome;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\LeftOutReason;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ShowExperimentHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string EXPERIMENT = 'model-test';

    private EntityManagerInterface $em;
    private Project $project;
    private \DateTimeImmutable $start;
    private ?\DateTimeImmutable $historyStart;

    /** @var array<string, CardOutcome> */
    private array $outcomes = [];

    /** @var array<string, CardColumn> */
    private array $columns = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
        $owner = $this->user($this->em, 'experiment-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $owner, 'Experiment');
        $this->start = new \DateTimeImmutable('2026-09-01 10:00:00');
        $this->historyStart = $this->start->modify('-30 days');
    }

    public function test_an_experiment_with_no_run_and_no_pin_has_no_report(): void
    {
        $this->experimentRun(Uuid::v7(), 'a', experiment: 'another-test');

        self::assertNull($this->show());
    }

    public function test_a_pin_alone_makes_a_report(): void
    {
        $card = Uuid::v7();
        $this->em->persist(new ExperimentPin($this->project, $card, self::EXPERIMENT, 'a'));
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals([new ExperimentCard($card, null, null, 'a', null, 0, 0, null, [LeftOutReason::NoRun])], $view->cards);
        self::assertSame(['a'], array_map(static fn (ExperimentVariant $variant): string => $variant->name, $view->variants));
    }

    public function test_it_keeps_every_reason_to_leave_a_card_out(): void
    {
        $switched = Uuid::v7();
        $this->experimentRun($switched, 'a', at: '+1 hour');
        $this->experimentRun($switched, 'a', at: '+2 hours', switchedFrom: 'b');

        $mixed = Uuid::v7();
        $this->experimentRun($mixed, 'a', at: '+1 hour');
        $this->experimentRun($mixed, 'b', at: '+2 hours');

        $switchedAndMixed = Uuid::v7();
        $this->experimentRun($switchedAndMixed, 'b', at: '+1 hour');
        $this->experimentRun($switchedAndMixed, 'a', at: '+2 hours', switchedFrom: 'b');

        $beforeTest = Uuid::v7();
        $this->plainRun($beforeTest, 'implement', '+1 hour');
        $this->experimentRun($beforeTest, 'a', at: '+2 hours');

        $otherKind = Uuid::v7();
        $this->plainRun($otherKind, 'design', '+1 hour');
        $this->experimentRun($otherKind, 'a', at: '+2 hours');

        $plainAfter = Uuid::v7();
        $this->experimentRun($plainAfter, 'a', at: '+1 hour');
        $this->plainRun($plainAfter, 'implement', '+2 hours');

        $noHistory = Uuid::v7();
        $this->experimentRun($noHistory, 'a', at: '-31 days');

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([
            (string) $switched => ['switched'],
            (string) $mixed => ['mixed'],
            (string) $switchedAndMixed => ['switched', 'mixed'],
            (string) $beforeTest => ['before-test'],
            (string) $otherKind => [],
            (string) $plainAfter => [],
            (string) $noHistory => ['no-history'],
        ], $this->reasonsById($view, [$switched, $mixed, $switchedAndMixed, $beforeTest, $otherKind, $plainAfter, $noHistory]));
        self::assertSame(2, $view->includedCards);
        self::assertSame(5, $view->leftOutCards);
        // A left-out card counts in no variant.
        self::assertSame([2, 0], array_map(static fn (ExperimentVariant $variant): int => $variant->cards, $view->variants));
    }

    public function test_a_card_has_no_history_when_the_project_has_none(): void
    {
        $this->historyStart = null;
        $card = Uuid::v7();
        $this->experimentRun($card, 'a', at: '+1 hour');

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([LeftOutReason::NoHistory], $view->cards[0]->leftOut);
    }

    public function test_a_card_with_a_pin_and_no_run_is_left_out_as_never_run_with_or_without_history(): void
    {
        $card = Uuid::v7();
        $this->em->persist(new ExperimentPin($this->project, $card, self::EXPERIMENT, 'a'));
        $this->em->flush();
        $this->outcomes[(string) $card] = new CardOutcome(merged: true);

        $withHistory = $this->show();
        $this->historyStart = null;
        $withoutHistory = $this->show();

        self::assertNotNull($withHistory);
        self::assertNotNull($withoutHistory);
        self::assertSame([LeftOutReason::NoRun], $withHistory->cards[0]->leftOut);
        self::assertSame([LeftOutReason::NoRun], $withoutHistory->cards[0]->leftOut);
        self::assertSame(0, $withHistory->includedCards);
        self::assertSame(1, $withHistory->leftOutCards);
        self::assertNull($withHistory->metrics[ExperimentMetric::MERGE_RATE]->for('a'));
    }

    public function test_a_merged_card_with_no_usage_stays_out_of_the_cost_and_token_samples_only(): void
    {
        $withUsage = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($withUsage, 'a', at: '+1 hour'), costUsd: '1.000000');
        $noUsage = Uuid::v7();
        $this->experimentRun($noUsage, 'a', at: '+2 hours');
        $this->outcomes[(string) $withUsage] = new CardOutcome(fixRounds: ['conflict' => 2], merged: true);
        $this->outcomes[(string) $noUsage] = new CardOutcome(merged: true);

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals(new Interval(1.0, 1.0, 1.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertEquals(new Interval(20.0, 20.0, 20.0), $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a'));
        self::assertEquals(Stats::wilson(2, 2), $view->metrics[ExperimentMetric::MERGE_RATE]->for('a'));
        self::assertSame(1.0, $view->metrics[ExperimentMetric::FIX_ROUNDS]->for('a')?->point);
        self::assertSame(1_000_000, $view->variants[0]->costMicros);
        self::assertSame([(string) $noUsage => null, (string) $withUsage => 1_000_000], array_combine(
            array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards),
            array_map(static fn (ExperimentCard $row): ?int => $row->costMicros, $view->cards),
        ));
    }

    public function test_it_ignores_the_rows_of_another_project(): void
    {
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');

        $other = $this->project($this->em, $this->user($this->em, 'experiment-other-'.uniqid().'@example.com'), 'Other experiment');
        $foreign = $this->seedRun($this->em, $other, receivedAt: $this->start->modify('+2 hours'), cardId: $card, state: WorkerRunState::Succeeded, runKey: Uuid::v7());
        $foreign->experiment = self::EXPERIMENT;
        $foreign->variant = 'b';
        $foreign->requestedModel = 'foreign-model';
        $this->seedUsage($this->em, $foreign, costUsd: '9.000000');
        $this->em->persist(new ExperimentPin($other, Uuid::v7(), self::EXPERIMENT, 'b'));
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals([new ExperimentVariant('a', 'claude-opus-5-5', null, 1, 0, 1, 1_000_000)], $view->variants);
        self::assertSame([(string) $card], array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards));
        self::assertSame(1_000_000, $view->cards[0]->costMicros);
    }

    public function test_the_cards_tab_skips_the_metrics(): void
    {
        $card = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($card, 'a'), costUsd: '1.000000');
        $this->outcomes[(string) $card] = new CardOutcome(merged: true);

        $view = $this->show(withMetrics: false);

        self::assertNotNull($view);
        self::assertSame([], $view->metrics);
        self::assertNull($view->headline);
        self::assertSame(1, $view->variants[0]->finishedCards);
        self::assertSame([(string) $card], array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards));
    }

    public function test_it_compares_the_variants_on_each_metric(): void
    {
        $this->em->persist(new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 70], ['name' => 'b', 'weight' => 30]]));
        $merged = static fn (array $fixRounds, ?float $hours = 2.0): CardOutcome => new CardOutcome(
            fixRounds: $fixRounds,
            merged: true,
            openedAt: null === $hours ? null : new \DateTimeImmutable('2026-09-02 08:00:00'),
            mergedAt: null === $hours ? null : new \DateTimeImmutable('2026-09-02 08:00:00')->modify(\sprintf('+%d minutes', (int) ($hours * 60))),
        );

        // Variant a: five merged cards, one finished by hand, one still open.
        $aFixRounds = [[], ['conflict' => 1], ['conflict' => 1, 'checks-failed' => 1], ['checks-failed' => 1], ['changes-requested' => 1]];
        foreach ($aFixRounds as $i => $fixRounds) {
            $card = Uuid::v7();
            $run = $this->experimentRun($card, 'a', at: \sprintf('+%d hours', $i + 1));
            $this->seedUsage($this->em, $run, costUsd: '1.000000');
            $this->outcomes[(string) $card] = $merged($fixRounds, 0 === $i ? null : 2.0);
            if (0 === $i) {
                foreach (['+20 hours' => WorkerRunState::Blocked, '+21 hours' => WorkerRunState::Failed, '+22 hours' => WorkerRunState::Stopped] as $at => $state) {
                    $stopped = $this->experimentRun($card, 'a', at: $at, state: $state);
                    $stopped->usageSource = WorkerRunUsageSource::Reported;
                    $this->em->persist(new WorkerRunUsage($stopped, $run->project, WorkSubject::CARD, $card, $stopped->workKind, 'claude-opus-5-5', WorkerRunUsageSource::Reported, 0, 0, 0, 0, '0.000000'));
                }
                // Neither a row whose run is gone nor the usage of a run outside the experiment counts.
                $this->em->persist(new WorkerRunUsage(null, $run->project, WorkSubject::CARD, $card, 'build', 'claude-opus-5-5', WorkerRunUsageSource::Reported, 1, 1, 0, 0, '5.000000'));
                $this->seedUsage($this->em, $this->plainRun($card, 'design', '-1 hour'), costUsd: '5.000000');
            }
        }
        $byHand = Uuid::v7();
        $this->experimentRun($byHand, 'a', at: '+6 hours');
        $this->columns[(string) $byHand] = new CardColumn('board.card.status.done', true);
        $open = Uuid::v7();
        $this->experimentRun($open, 'a', at: '+7 hours');
        $this->columns[(string) $open] = new CardColumn('board.card.status.in-progress', false);

        // Variant b: five merged cards, each with one fix round, at half the cost.
        for ($i = 0; $i < 5; ++$i) {
            $card = Uuid::v7();
            $this->seedUsage($this->em, $this->experimentRun($card, 'b', at: \sprintf('+%d hours', $i + 10), model: 'claude-sonnet-5'), costUsd: '0.500000');
            $this->outcomes[(string) $card] = $merged(['conflict' => 1], 4.0);
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertEquals([
            new ExperimentVariant('a', 'claude-opus-5-5', 70, 7, 6, 10, 5_000_000),
            new ExperimentVariant('b', 'claude-sonnet-5', 30, 5, 5, 5, 2_500_000),
        ], $view->variants);
        self::assertSame([ExperimentMetric::MERGE_RATE, ExperimentMetric::STOP_RATE, ExperimentMetric::FIX_ROUNDS, ExperimentMetric::COST, ExperimentMetric::OUTPUT_TOKENS, ExperimentMetric::HOURS_TO_MERGE], array_keys($view->metrics));

        $mergeRate = $view->metrics[ExperimentMetric::MERGE_RATE];
        self::assertEquals(Stats::wilson(5, 6), $mergeRate->for('a'));
        self::assertEquals(Stats::wilson(5, 5), $mergeRate->for('b'));
        self::assertFalse($mergeRate->clear);

        $stopRate = $view->metrics[ExperimentMetric::STOP_RATE];
        self::assertEquals(Stats::wilson(2, 9), $stopRate->for('a'));
        self::assertEquals(Stats::wilson(0, 5), $stopRate->for('b'));

        $fixRounds = $view->metrics[ExperimentMetric::FIX_ROUNDS];
        self::assertEquals(Stats::bootstrapMean([0, 1, 2, 1, 1], 'model-test:fix-rounds:a'), $fixRounds->for('a'));
        self::assertSame(1.0, $fixRounds->for('b')?->point);
        self::assertTrue($fixRounds->clear);
        self::assertSame(['changes-requested', 'checks-failed', 'conflict'], array_map(static fn (ExperimentMetric $part): string => $part->key, $fixRounds->parts));
        self::assertEquals(Stats::bootstrapMean([0, 1, 1, 0, 0], 'model-test:fix-rounds:conflict:a'), $fixRounds->parts[2]->for('a'));
        self::assertSame(0.0, $fixRounds->parts[0]->for('b')?->point);

        $cost = $view->metrics[ExperimentMetric::COST];
        self::assertEquals(new Interval(1.0, 1.0, 1.0), $cost->for('a'));
        self::assertEquals(new Interval(0.5, 0.5, 0.5), $cost->for('b'));
        self::assertTrue($cost->clear);

        self::assertSame(20.0, $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a')?->point);

        $hours = $view->metrics[ExperimentMetric::HOURS_TO_MERGE];
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $hours->for('a'));
        self::assertEquals(new Interval(4.0, 4.0, 4.0), $hours->for('b'));

        self::assertNotNull($view->headline);
        self::assertSame('b', $view->headline->cheaperVariant);
        self::assertSame(0.5, $view->headline->saving);
        self::assertTrue($view->headline->costClear);
        self::assertFalse($view->headline->qualitySettled);
    }

    public function test_too_few_finished_cards_give_no_clear_answer(): void
    {
        foreach (['a' => '2.000000', 'b' => '0.500000'] as $variant => $cost) {
            for ($i = 0; $i < Stats::MIN_FINISHED_CARDS - 1; ++$i) {
                $card = Uuid::v7();
                $this->seedUsage($this->em, $this->experimentRun($card, $variant, at: \sprintf('+%d hours', $i + 1)), costUsd: $cost);
                $this->outcomes[(string) $card] = new CardOutcome(merged: true);
            }
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        $cost = $view->metrics[ExperimentMetric::COST];
        self::assertNotNull($cost->for('a'));
        self::assertNotNull($cost->for('b'));
        self::assertFalse($cost->clear);
        self::assertNotNull($view->headline);
        self::assertFalse($view->headline->costClear);
        self::assertSame('b', $view->headline->cheaperVariant);
    }

    public function test_a_metric_judges_the_floor_on_its_own_sample(): void
    {
        foreach (['a' => '2.000000', 'b' => '0.500000'] as $variant => $cost) {
            for ($i = 0; $i < Stats::MIN_FINISHED_CARDS; ++$i) {
                $card = Uuid::v7();
                $run = $this->experimentRun($card, $variant, at: \sprintf('+%d hours', $i + 1));
                if (0 === $i) {
                    $this->seedUsage($this->em, $run, costUsd: $cost);
                }
                $this->outcomes[(string) $card] = new CardOutcome(
                    merged: true,
                    openedAt: 0 === $i ? new \DateTimeImmutable('2026-09-02 08:00:00') : null,
                    mergedAt: 0 === $i ? new \DateTimeImmutable('2026-09-02 '.('a' === $variant ? '10' : '09').':00:00') : null,
                );
            }
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([5, 5], array_map(static fn (ExperimentVariant $variant): int => $variant->finishedCards, $view->variants));
        // Five finished cards each, and one cost, one token count and one duration each.
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertEquals(new Interval(0.5, 0.5, 0.5), $view->metrics[ExperimentMetric::COST]->for('b'));
        self::assertFalse($view->metrics[ExperimentMetric::COST]->clear);
        self::assertFalse($view->metrics[ExperimentMetric::OUTPUT_TOKENS]->clear);
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $view->metrics[ExperimentMetric::HOURS_TO_MERGE]->for('a'));
        self::assertFalse($view->metrics[ExperimentMetric::HOURS_TO_MERGE]->clear);
        // Every finished card is in the fix rounds sample, so the floor holds there.
        self::assertTrue($view->metrics[ExperimentMetric::FIX_ROUNDS]->clear);
        self::assertTrue($view->metrics[ExperimentMetric::MERGE_RATE]->clear);
    }

    public function test_a_variant_with_no_usage_has_no_total_cost(): void
    {
        $this->seedUsage($this->em, $this->experimentRun(Uuid::v7(), 'a'), costUsd: '0.000000');
        $this->experimentRun(Uuid::v7(), 'b');
        $this->em->persist(new ExperimentDefinition($this->project, self::EXPERIMENT, [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1], ['name' => 'c', 'weight' => 1]]));
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        // A recorded zero stays a zero.
        self::assertSame(['a' => 0, 'b' => null, 'c' => null], array_combine(
            array_map(static fn (ExperimentVariant $variant): string => $variant->name, $view->variants),
            array_map(static fn (ExperimentVariant $variant): ?int => $variant->costMicros, $view->variants),
        ));
    }

    public function test_a_card_with_an_unpriced_usage_row_has_an_unknown_cost(): void
    {
        $unpriced = Uuid::v7();
        $run = $this->experimentRun($unpriced, 'a', at: '+1 hour');
        $this->seedUsage($this->em, $run, costUsd: '1.000000');
        $this->seedUsage($this->em, $run, model: 'unpriced-model', costUsd: null);
        $priced = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($priced, 'a', at: '+2 hours'), costUsd: '2.000000');
        $onlyUnpriced = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($onlyUnpriced, 'b', at: '+3 hours'), model: 'unpriced-model', costUsd: null);
        foreach ([$unpriced, $priced, $onlyUnpriced] as $card) {
            $this->outcomes[(string) $card] = new CardOutcome(merged: true);
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([(string) $onlyUnpriced => null, (string) $priced => 2_000_000, (string) $unpriced => null], array_combine(
            array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards),
            array_map(static fn (ExperimentCard $row): ?int => $row->costMicros, $view->cards),
        ));
        self::assertSame([2_000_000, null], array_map(static fn (ExperimentVariant $variant): ?int => $variant->costMicros, $view->variants));
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertNull($view->metrics[ExperimentMetric::COST]->for('b'));
        // The tokens of an unpriced row are still known.
        self::assertSame(30.0, $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a')?->point);
        self::assertSame(20.0, $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('b')?->point);
    }

    public function test_a_pin_that_names_another_variant_than_the_runs_mixes_the_card(): void
    {
        $switched = Uuid::v7();
        $this->experimentRun($switched, 'a', at: '+1 hour');
        $this->em->persist(new ExperimentPin($this->project, $switched, self::EXPERIMENT, 'b'));
        $agreed = Uuid::v7();
        $this->experimentRun($agreed, 'a', at: '+2 hours');
        $this->em->persist(new ExperimentPin($this->project, $agreed, self::EXPERIMENT, 'a'));
        $pinOnly = Uuid::v7();
        $this->em->persist(new ExperimentPin($this->project, $pinOnly, self::EXPERIMENT, 'b'));
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([LeftOutReason::Mixed], $this->card($view, $switched)->leftOut);
        self::assertSame('a', $this->card($view, $switched)->variant);
        self::assertSame([], $this->card($view, $agreed)->leftOut);
        self::assertSame('a', $this->card($view, $agreed)->variant);
        self::assertSame('b', $this->card($view, $pinOnly)->variant);
    }

    public function test_a_single_variant_is_never_clear(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $card = Uuid::v7();
            $this->seedUsage($this->em, $this->experimentRun($card, 'a', at: \sprintf('+%d hours', $i + 1)), costUsd: '1.000000');
            $this->outcomes[(string) $card] = new CardOutcome(merged: true);
        }
        $this->em->flush();

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame(1.0, $view->metrics[ExperimentMetric::MERGE_RATE]->for('a')?->point);
        foreach ($view->metrics as $metric) {
            self::assertFalse($metric->clear, $metric->key);
        }
        self::assertNotNull($view->headline);
        self::assertNull($view->headline->cheaperVariant);
        self::assertNull($view->headline->saving);
        self::assertFalse($view->headline->qualitySettled);
    }

    public function test_the_cards_tab_pages_and_filters_the_cards(): void
    {
        $ids = [];
        for ($i = 0; $i < 22; ++$i) {
            $ids[] = $card = Uuid::v7();
            $this->experimentRun($card, 'a', at: \sprintf('+%d hours', $i + 1));
        }
        $this->experimentRun($b = Uuid::v7(), 'b', at: '+100 hours');
        $this->experimentRun($leftOut = Uuid::v7(), 'b', at: '+101 hours', switchedFrom: 'a');

        $first = $this->show();
        $second = $this->show(page: 2);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(24, $first->filteredTotal);
        self::assertSame(2, $first->totalPages);
        self::assertSame([(string) $leftOut, (string) $b], array_map(static fn (ExperimentCard $card): string => (string) $card->cardId, \array_slice($first->cards, 0, 2)));
        self::assertCount(20, $first->cards);
        self::assertSame([(string) $ids[1], (string) $ids[0]], array_map(static fn (ExperimentCard $card): string => (string) $card->cardId, \array_slice($second->cards, -2)));
        self::assertCount(4, $second->cards);
        self::assertSame(2, $this->show(page: 9)?->clampedPage);

        $onlyB = $this->show(variant: 'b');
        $onlyLeftOut = $this->show(leftOutOnly: true);
        self::assertNotNull($onlyB);
        self::assertNotNull($onlyLeftOut);
        self::assertSame([(string) $b], array_map(static fn (ExperimentCard $card): string => (string) $card->cardId, $onlyB->cards));
        self::assertSame([(string) $leftOut], array_map(static fn (ExperimentCard $card): string => (string) $card->cardId, $onlyLeftOut->cards));
    }

    private function show(int $page = 1, ?string $variant = null, bool $leftOutOnly = false, bool $withMetrics = true): ?ExperimentReportView
    {
        $this->em->clear();
        $reports = new readonly class($this->outcomes, $this->columns, $this->historyStart) implements CardReportSourceInterface {
            /**
             * @param array<string, CardOutcome> $outcomes
             * @param array<string, CardColumn>  $columns
             */
            public function __construct(
                private array $outcomes,
                private array $columns,
                private ?\DateTimeImmutable $historyStart,
            ) {
            }

            #[\Override]
            public function columnsFor(Project $project, array $cardIds): array
            {
                return $this->columns;
            }

            #[\Override]
            public function outcomesFor(Project $project, array $cardIds): array
            {
                return $this->outcomes;
            }

            #[\Override]
            public function typesFor(Project $project, array $cardIds): array
            {
                return [];
            }

            #[\Override]
            public function historyStartFor(Project $project): ?\DateTimeImmutable
            {
                return $this->historyStart;
            }
        };
        $titles = new readonly class implements CardTitleSourceInterface {
            #[\Override]
            public function titlesFor(Project $project, array $cardIds): array
            {
                return [];
            }
        };
        $container = self::getContainer();
        $handler = new ShowExperimentHandler(
            $container->get(WorkerRunRepository::class),
            $container->get(ExperimentPinRepository::class),
            $container->get(ExperimentDefinitionRepository::class),
            $container->get(WorkerRunFactRepository::class),
            $reports,
            $titles,
        );

        return $handler(new ShowExperimentCommand($this->managedProject(), self::EXPERIMENT, $page, $variant, $leftOutOnly, $withMetrics));
    }

    private function managedProject(): Project
    {
        return $this->em->find(Project::class, $this->project->id) ?? throw new \LogicException('The project exists.');
    }

    public function test_a_started_run_with_no_usage_makes_its_card_cost_unknown(): void
    {
        $partial = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($partial, 'a', at: '+1 hour'), costUsd: '1.000000');
        $this->experimentRun($partial, 'a', at: '+2 hours', state: WorkerRunState::Failed);
        $queued = Uuid::v7();
        $this->seedUsage($this->em, $this->experimentRun($queued, 'a', at: '+3 hours'), costUsd: '2.000000');
        $this->experimentRun($queued, 'a', at: '+4 hours', state: WorkerRunState::Queued)->startedAt = null;
        $this->experimentRun($queued, 'a', at: '+5 hours', state: WorkerRunState::Stopped)->startedAt = null;
        $this->em->flush();
        foreach ([$partial, $queued] as $card) {
            $this->outcomes[(string) $card] = new CardOutcome(merged: true);
        }

        $view = $this->show();

        self::assertNotNull($view);
        self::assertSame([(string) $queued => 2_000_000, (string) $partial => null], array_combine(
            array_map(static fn (ExperimentCard $row): string => (string) $row->cardId, $view->cards),
            array_map(static fn (ExperimentCard $row): ?int => $row->costMicros, $view->cards),
        ));
        self::assertEquals(new Interval(2.0, 2.0, 2.0), $view->metrics[ExperimentMetric::COST]->for('a'));
        self::assertEquals(new Interval(20.0, 20.0, 20.0), $view->metrics[ExperimentMetric::OUTPUT_TOKENS]->for('a'));
    }

    private function experimentRun(
        Uuid $cardId,
        string $variant,
        string $at = '+1 hour',
        WorkerRunState $state = WorkerRunState::Succeeded,
        ?string $switchedFrom = null,
        ?string $model = 'claude-opus-5-5',
        string $experiment = self::EXPERIMENT,
    ): WorkerRun {
        // A run with no key reads as one from an older bridge, and a trigger rewrites its failed state.
        $run = $this->seedRun($this->em, $this->managedProject(), receivedAt: $this->start->modify($at), workKind: 'implement', cardId: $cardId, state: $state, runKey: Uuid::v7());
        $run->experiment = $experiment;
        $run->variant = $variant;
        $run->switchedFrom = $switchedFrom;
        $run->requestedModel = $model;
        $this->em->flush();

        return $run;
    }

    private function plainRun(Uuid $cardId, string $workKind, string $at): WorkerRun
    {
        return $this->seedRun($this->em, $this->managedProject(), receivedAt: $this->start->modify($at), workKind: $workKind, cardId: $cardId, state: WorkerRunState::Succeeded);
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, list<string>>
     */
    private function reasonsById(ExperimentReportView $view, array $ids): array
    {
        $reasons = [];
        foreach ($ids as $id) {
            $reasons[(string) $id] = array_map(static fn (LeftOutReason $reason): string => $reason->value, $this->card($view, $id)->leftOut);
        }

        return $reasons;
    }

    private function card(ExperimentReportView $view, Uuid $id): ExperimentCard
    {
        foreach ($view->cards as $card) {
            if ($card->cardId->equals($id)) {
                return $card;
            }
        }

        throw new \LogicException('The card is on the page.');
    }
}
