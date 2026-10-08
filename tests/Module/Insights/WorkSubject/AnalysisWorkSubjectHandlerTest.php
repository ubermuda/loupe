<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\WorkSubject\AnalysisWorkSubjectHandler;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class AnalysisWorkSubjectHandlerTest extends KernelTestCase
{
    use InsightsScenario;

    private const string NOW = '2026-10-07 12:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    public function test_bridge_finds_the_handler_of_an_analysis(): void
    {
        self::assertInstanceOf(AnalysisWorkSubjectHandler::class, $this->handlers()->for('analysis'));
    }

    public function test_a_refused_request_fails_the_analysis_with_its_reason(): void
    {
        $project = $this->scenarioProject('subject-refused');
        $analysis = $this->seedAnalysis($this->em(), $project, AnalysisState::Running);

        $this->handler()->onSettled($this->request($project, $analysis->id, WorkRequestState::Refused, 'no-capacity'));

        $stored = $this->stored($analysis);
        self::assertSame(AnalysisState::Failed, $stored->state);
        self::assertSame('no-capacity', $stored->reason);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $stored->finishedAt);
    }

    public function test_a_refused_request_with_no_reason_fails_as_refused(): void
    {
        $project = $this->scenarioProject('subject-refused-plain');
        $analysis = $this->seedAnalysis($this->em(), $project);

        $this->handler()->onSettled($this->request($project, $analysis->id, WorkRequestState::Refused));

        self::assertSame(AnalysisWorkSubjectHandler::REFUSED, $this->stored($analysis)->reason);
    }

    public function test_a_done_request_without_a_report_fails_the_analysis(): void
    {
        $project = $this->scenarioProject('subject-no-report');
        $analysis = $this->seedAnalysis($this->em(), $project, AnalysisState::Running);

        $this->handler()->onSettled($this->request($project, $analysis->id, WorkRequestState::Done));

        $stored = $this->stored($analysis);
        self::assertSame(AnalysisState::Failed, $stored->state);
        self::assertSame(AnalysisWorkSubjectHandler::NO_REPORT, $stored->reason);
    }

    /** @return iterable<string, array{AnalysisState, WorkRequestState}> */
    public static function finished(): iterable
    {
        yield 'a reported analysis, then done' => [AnalysisState::Done, WorkRequestState::Done];
        yield 'a reported analysis, then refused' => [AnalysisState::Done, WorkRequestState::Refused];
        yield 'a failed analysis, then refused' => [AnalysisState::Failed, WorkRequestState::Refused];
    }

    #[DataProvider('finished')]
    public function test_a_finished_analysis_does_not_change(AnalysisState $state, WorkRequestState $requestState): void
    {
        $project = $this->scenarioProject('subject-finished');
        $analysis = $this->seedAnalysis($this->em(), $project, $state);

        $this->handler()->onSettled($this->request($project, $analysis->id, $requestState, 'late'));

        $stored = $this->stored($analysis);
        self::assertSame($state, $stored->state);
        self::assertNull($stored->reason);
    }

    public function test_an_expired_request_pauses_the_analysis(): void
    {
        $project = $this->scenarioProject('subject-expired');
        $analysis = $this->seedAnalysis($this->em(), $project);

        $this->handler()->onExpired($this->request($project, $analysis->id, WorkRequestState::Expired));

        $stored = $this->stored($analysis);
        self::assertSame(AnalysisState::Paused, $stored->state);
        self::assertSame(AnalysisWorkSubjectHandler::NO_BRIDGE_TOOK_WORK, $stored->reason);
    }

    public function test_a_request_about_no_stored_analysis_changes_nothing(): void
    {
        $project = $this->scenarioProject('subject-unknown');
        $handler = $this->handler();

        $handler->onSettled($this->request($project, Uuid::v7(), WorkRequestState::Done));
        $handler->onExpired($this->request($project, Uuid::v7(), WorkRequestState::Expired));

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_analyses WHERE project_id = ?', [(string) $project->id]));
    }

    private function request(Project $project, ?Uuid $analysisId, WorkRequestState $state, ?string $reason = null): WorkRequest
    {
        $request = $this->seedWorkRequest($this->em(), $project, kind: 'analysis', state: $state, ruleId: 'insights.analysis', subject: new WorkSubject('analysis', $analysisId ?? throw new \LogicException('A stored analysis has an id.')));
        $request->reason = $reason;

        return $request;
    }

    private function stored(Analysis $analysis): Analysis
    {
        $this->em()->clear();
        $repository = self::getContainer()->get(AnalysisRepository::class);
        self::assertInstanceOf(AnalysisRepository::class, $repository);
        $stored = $repository->find($analysis->id);
        self::assertInstanceOf(Analysis::class, $stored);

        return $stored;
    }

    private function handler(): AnalysisWorkSubjectHandler
    {
        $handler = $this->handlers()->for('analysis');
        self::assertInstanceOf(AnalysisWorkSubjectHandler::class, $handler);

        return $handler;
    }

    private function handlers(): WorkSubjectHandlers
    {
        $handlers = self::getContainer()->get(WorkSubjectHandlers::class);
        self::assertInstanceOf(WorkSubjectHandlers::class, $handlers);

        return $handlers;
    }
}
