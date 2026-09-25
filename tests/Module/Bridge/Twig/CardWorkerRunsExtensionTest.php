<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Twig;

use App\Module\Bridge\Twig\CardWorkerRunsExtension;
use App\Module\Bridge\ValueObject\WorkerRunState;
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
}
