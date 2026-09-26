<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Twig;

use App\Module\Bridge\Twig\CardWorkerRunsExtension;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\View\WorkerRunListItem;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardWorkerRunsExtensionTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_returns_the_cards_latest_runs_in_this_project_only(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'card-runs@example.com');
        $project = $this->project($em, $owner, 'Runs');
        $other = $this->project($em, $owner, 'Other runs');
        $cardId = Uuid::v7();
        for ($minute = 1; $minute <= 7; ++$minute) {
            $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 11:0'.$minute.':00'), ruleName: 'rule-'.$minute, cardId: $cardId);
        }
        $this->seedRun($em, $project, ruleName: 'another card');
        $this->seedRun($em, $other, ruleName: 'another project', cardId: $cardId);

        $runs = self::getContainer()->get(CardWorkerRunsExtension::class)->cardWorkerRuns($project, (string) $cardId);

        self::assertSame(
            ['rule-7', 'rule-6', 'rule-5', 'rule-4', 'rule-3'],
            array_map(static fn (WorkerRunListItem $item): string => $item->run->ruleName, $runs),
        );
    }

    /** A run still in flight is what a reader looks for, so it stays in the five however old it is. */
    public function test_an_open_run_comes_before_newer_closed_runs(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-open-runs@example.com'), 'Open runs');
        $cardId = Uuid::v7();
        $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 09:00:00'), ruleName: 'running', cardId: $cardId, state: WorkerRunState::Running);
        for ($minute = 1; $minute <= 5; ++$minute) {
            $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 11:0'.$minute.':00'), ruleName: 'closed-'.$minute, cardId: $cardId);
        }
        $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 10:00:00'), ruleName: 'queued', cardId: $cardId, state: WorkerRunState::Queued);

        $runs = self::getContainer()->get(CardWorkerRunsExtension::class)->cardWorkerRuns($project, (string) $cardId);

        self::assertSame(
            ['queued', 'running', 'closed-5', 'closed-4', 'closed-3'],
            array_map(static fn (WorkerRunListItem $item): string => $item->run->ruleName, $runs),
        );
    }

    public function test_a_card_with_no_runs_gets_an_empty_list(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-no-runs@example.com'), 'Quiet');

        self::assertSame([], self::getContainer()->get(CardWorkerRunsExtension::class)->cardWorkerRuns($project, (string) Uuid::v7()));
    }

    public function test_a_card_whose_latest_outcome_gave_up_or_is_blocked_has_a_warning(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'card-warnings@example.com');
        $project = $this->project($em, $owner, 'Warnings');
        $other = $this->project($em, $owner, 'Other warnings');
        $gaveUp = Uuid::v7();
        $blocked = Uuid::v7();
        $cleared = Uuid::v7();
        $stillRunning = Uuid::v7();
        $failed = Uuid::v7();
        $elsewhere = Uuid::v7();
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-01-01 '.$time);

        $this->seedRun($em, $project, receivedAt: $at('10:00'), cardId: $gaveUp, state: WorkerRunState::Unfinished, hasResult: true);
        $lastTry = $this->seedRun($em, $project, receivedAt: $at('10:10'), output: 'Tests still fail.', cardId: $gaveUp, state: WorkerRunState::GaveUp, hasResult: true);
        $lastTry->cardColumn = 'implementation';
        $blockedRun = $this->seedRun($em, $project, receivedAt: $at('10:00'), output: 'Needs a token.', cardId: $blocked, state: WorkerRunState::Blocked, hasResult: true);
        $this->seedRun($em, $project, receivedAt: $at('10:00'), cardId: $cleared, state: WorkerRunState::GaveUp, hasResult: true);
        $this->seedRun($em, $project, receivedAt: $at('10:05'), cardId: $cleared, state: WorkerRunState::Succeeded, hasResult: true);
        $waiting = $this->seedRun($em, $project, receivedAt: $at('10:00'), cardId: $stillRunning, state: WorkerRunState::Blocked, hasResult: true);
        $this->seedRun($em, $project, receivedAt: $at('10:05'), cardId: $stillRunning, state: WorkerRunState::Running);
        $this->seedRun($em, $project, receivedAt: $at('10:00'), exitCode: 1, cardId: $failed);
        $this->seedRun($em, $other, receivedAt: $at('10:00'), cardId: $elsewhere, state: WorkerRunState::GaveUp, hasResult: true);
        $em->flush();

        $warnings = self::getContainer()->get(CardWorkerRunsExtension::class)->cardRunWarnings($project);

        ksort($warnings);
        $expected = [(string) $gaveUp, (string) $blocked, (string) $stillRunning];
        sort($expected);
        self::assertSame($expected, array_keys($warnings));

        $warning = $warnings[(string) $gaveUp];
        self::assertSame((string) $lastTry->id, $warning->runId);
        self::assertSame(WorkerRunState::GaveUp, $warning->state);
        self::assertSame('Tests still fail.', $warning->summary);
        self::assertSame('implementation', $warning->cardColumn);

        self::assertSame((string) $blockedRun->id, $warnings[(string) $blocked]->runId);
        self::assertNull($warnings[(string) $blocked]->cardColumn);
        // An open run is not an outcome, so the blocked outcome before it still stands.
        self::assertSame((string) $waiting->id, $warnings[(string) $stillRunning]->runId);
    }

    public function test_a_project_with_no_runs_has_no_warnings(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-no-warnings@example.com'), 'Calm');

        self::assertSame([], self::getContainer()->get(CardWorkerRunsExtension::class)->cardRunWarnings($project));
    }

    public function test_the_usage_total_sums_every_row_of_the_card_even_when_its_run_is_gone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'card-usage-total@example.com');
        $project = $this->project($em, $owner, 'Usage total');
        $other = $this->project($em, $owner, 'Other usage');
        $cardId = Uuid::v7();
        for ($minute = 1; $minute <= 6; ++$minute) {
            $this->seedUsage($em, $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 11:0'.$minute.':00'), cardId: $cardId));
        }
        $deleted = $this->seedRun($em, $project, cardId: $cardId);
        $this->seedUsage($em, $deleted);
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = :id', ['id' => (string) $deleted->id]);
        $this->seedUsage($em, $this->seedRun($em, $project));
        $this->seedUsage($em, $this->seedRun($em, $other, cardId: $cardId));

        $total = self::getContainer()->get(CardWorkerRunsExtension::class)->cardUsageTotal($project, (string) $cardId);

        self::assertTrue($total->known);
        self::assertSame('0.086415', $total->costUsd);
        self::assertSame(700, $total->inputTokens);
        self::assertSame(140, $total->outputTokens);
        self::assertSame(2100, $total->cacheReadTokens);
        self::assertSame(280, $total->cacheWriteTokens);
        self::assertSame(0, $total->partialRuns);
        self::assertFalse($total->estimated);
    }

    public function test_only_a_closed_worker_run_that_started_and_has_no_usage_is_partial(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-usage-partial@example.com'), 'Usage partial');
        $cardId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $cardId));
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Failed);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::TimedOut);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Running);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Queued);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::NotStarted);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Closed, kind: WorkerRunKind::Interactive);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Replaced)->startedAt = null;
        $this->seedRun($em, $project, cardId: $cardId)->usageSource = WorkerRunUsageSource::Reported;
        $em->flush();

        $total = self::getContainer()->get(CardWorkerRunsExtension::class)->cardUsageTotal($project, (string) $cardId);

        self::assertTrue($total->known);
        self::assertSame(2, $total->partialRuns);
    }

    public function test_a_card_whose_runs_report_no_usage_has_unknown_usage(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-usage-unknown@example.com'), 'Usage unknown');
        $cardId = Uuid::v7();
        $this->seedRun($em, $project, cardId: $cardId);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Running);

        $total = self::getContainer()->get(CardWorkerRunsExtension::class)->cardUsageTotal($project, (string) $cardId);

        self::assertFalse($total->known);
        self::assertSame(1, $total->partialRuns);
    }

    public function test_a_run_that_reported_no_models_has_a_known_zero_cost(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-usage-zero@example.com'), 'Usage zero');
        $cardId = Uuid::v7();
        $this->seedRun($em, $project, cardId: $cardId)->usageSource = WorkerRunUsageSource::Estimated;
        $em->flush();

        $total = self::getContainer()->get(CardWorkerRunsExtension::class)->cardUsageTotal($project, (string) $cardId);

        self::assertTrue($total->known);
        self::assertSame('0', $total->costUsd);
        self::assertSame(0, $total->inputTokens);
        self::assertFalse($total->estimated);
    }

    public function test_an_estimated_row_marks_the_total_estimated(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-usage-estimated@example.com'), 'Usage estimated');
        $cardId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $cardId));
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $cardId), source: WorkerRunUsageSource::Estimated);

        $total = self::getContainer()->get(CardWorkerRunsExtension::class)->cardUsageTotal($project, (string) $cardId);

        self::assertTrue($total->estimated);
        self::assertSame('0.024690', $total->costUsd);
    }

    public function test_a_card_with_only_unpriced_rows_has_no_dollar_total(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-usage-unpriced@example.com'), 'Usage unpriced');
        $cardId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $cardId), source: WorkerRunUsageSource::Estimated, costUsd: null);

        $total = self::getContainer()->get(CardWorkerRunsExtension::class)->cardUsageTotal($project, (string) $cardId);

        self::assertTrue($total->known);
        self::assertNull($total->costUsd);
        self::assertSame(100, $total->inputTokens);
        self::assertTrue($total->estimated);
    }

    /** A reported row with no price still leaves the dollars short, so it reads as estimated. */
    public function test_an_unpriced_reported_row_marks_the_total_estimated(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-usage-unpriced-reported@example.com'), 'Usage unpriced reported');
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, cardId: $cardId);
        $this->seedUsage($em, $run);
        $this->seedUsage($em, $run, model: 'claude-unpriced', costUsd: null);

        $total = self::getContainer()->get(CardWorkerRunsExtension::class)->cardUsageTotal($project, (string) $cardId);

        self::assertTrue($total->estimated);
        self::assertSame('0.012345', $total->costUsd);
    }

    public function test_a_card_id_that_is_not_a_uuid_has_unknown_usage(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-usage-bad-id@example.com'), 'Usage bad id');

        self::assertFalse(self::getContainer()->get(CardWorkerRunsExtension::class)->cardUsageTotal($project, 'not-a-uuid')->known);
    }
}
