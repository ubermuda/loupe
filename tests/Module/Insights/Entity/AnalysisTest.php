<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AnalysisTest extends TestCase
{
    private const string NOW = '2026-10-07 12:00:00';

    public function test_a_new_analysis_waits_and_keeps_its_scope(): void
    {
        $analysis = $this->analysis();

        self::assertSame(AnalysisState::Waiting, $analysis->state);
        self::assertSame(MetricRange::ThirtyDays, $analysis->scope->range);
        self::assertNull($analysis->finishedAt);
    }

    public function test_a_waiting_analysis_starts_and_completes(): void
    {
        $analysis = $this->analysis();
        $documentId = Uuid::v7();

        $analysis->start();
        self::assertSame(AnalysisState::Running, $analysis->state);
        $analysis->complete($documentId, new \DateTimeImmutable(self::NOW));

        self::assertSame(AnalysisState::Done, $analysis->state);
        self::assertSame($documentId, $analysis->documentId);
        self::assertNull($analysis->reason);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $analysis->finishedAt);
    }

    public function test_a_failed_analysis_keeps_its_reason(): void
    {
        $analysis = $this->analysis();

        $analysis->fail('no-report', new \DateTimeImmutable(self::NOW));

        self::assertSame(AnalysisState::Failed, $analysis->state);
        self::assertSame('no-report', $analysis->reason);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $analysis->finishedAt);
    }

    public function test_a_paused_analysis_can_still_complete(): void
    {
        $analysis = $this->analysis();

        $analysis->pause('no-bridge-took-work', new \DateTimeImmutable(self::NOW));
        self::assertSame(AnalysisState::Paused, $analysis->state);
        self::assertSame('no-bridge-took-work', $analysis->reason);
        $analysis->complete(Uuid::v7(), new \DateTimeImmutable(self::NOW));

        self::assertSame(AnalysisState::Done, $analysis->state);
        self::assertNull($analysis->reason);
    }

    /** @return iterable<string, array{AnalysisState, \Closure(Analysis): void}> */
    public static function refusedTransitions(): iterable
    {
        $now = new \DateTimeImmutable(self::NOW);
        yield 'a running analysis starts again' => [AnalysisState::Running, static fn (Analysis $a) => $a->start()];
        yield 'a done analysis fails' => [AnalysisState::Done, static fn (Analysis $a) => $a->fail('late', $now)];
        yield 'a failed analysis completes' => [AnalysisState::Failed, static fn (Analysis $a) => $a->complete(Uuid::v7(), $now)];
        yield 'a done analysis pauses' => [AnalysisState::Done, static fn (Analysis $a) => $a->pause('late', $now)];
        yield 'a paused analysis pauses again' => [AnalysisState::Paused, static fn (Analysis $a) => $a->pause('late', $now)];
    }

    /** @param \Closure(Analysis): void $transition */
    #[DataProvider('refusedTransitions')]
    public function test_a_transition_from_the_wrong_state_throws(AnalysisState $from, \Closure $transition): void
    {
        $analysis = $this->analysis();
        $analysis->state = $from;

        $this->expectException(\LogicException::class);
        $transition($analysis);
    }

    public function test_a_long_reason_is_cut_to_the_column(): void
    {
        $analysis = $this->analysis();

        $analysis->fail(str_repeat('a', 100), new \DateTimeImmutable(self::NOW));

        self::assertSame(Analysis::MAX_REASON_LENGTH, mb_strlen($analysis->reason ?? ''));
    }

    private function analysis(): Analysis
    {
        $project = new Project(new User(fullName: 'Riley Chen', email: 'analysis@example.com', password: 'x'), 'Analysis');

        return new Analysis($project, AnalysisTopic::Cost, new AnalysisScope(MetricRange::ThirtyDays), null, 'sonnet', 'medium', new \DateTimeImmutable(self::NOW));
    }
}
