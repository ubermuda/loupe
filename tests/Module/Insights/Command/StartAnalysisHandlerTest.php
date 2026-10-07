<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Insights\Command\StartAnalysisCommand;
use App\Module\Insights\Command\StartAnalysisHandler;
use App\Module\Insights\Command\UpdateAnalyticsSettingsCommand;
use App\Module\Insights\Command\UpdateAnalyticsSettingsHandler;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Service\AnalysisSettings;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Insights\InsightsScenario;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Ubermuda\AuditBundle\Auditor;

final class StartAnalysisHandlerTest extends KernelTestCase
{
    use InsightsScenario;

    private const string NOW = '2026-10-07 12:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    public function test_it_stores_a_waiting_analysis_and_opens_its_work_request(): void
    {
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $project = $this->scenarioProject('start-analysis');

        $analysis = $this->handler()(new StartAnalysisCommand($project, AnalysisTopic::Cost, MetricRange::NinetyDays));

        $this->em()->clear();
        $stored = $this->analyses()->find($analysis->id);
        self::assertInstanceOf(Analysis::class, $stored);
        self::assertSame(AnalysisState::Waiting, $stored->state);
        self::assertSame(AnalysisTopic::Cost, $stored->topic);
        self::assertSame(MetricRange::NinetyDays, $stored->scope->range);
        self::assertSame(AnalysisSettings::DEFAULT_MODEL, $stored->model);
        self::assertSame(AnalysisSettings::DEFAULT_EFFORT, $stored->effort);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $stored->createdAt);

        $request = self::getContainer()->get(WorkRequestRepository::class)->find($stored->workRequestId);
        self::assertNotNull($request);
        self::assertSame('analysis', $request->subjectType);
        self::assertSame((string) $stored->id, (string) $request->subjectId);
        self::assertNull($request->cardNumber);
        self::assertSame('analysis', $request->kind);
        self::assertSame('subject-analysis', $request->capability);
        self::assertSame('insights.analysis', $request->ruleId);
        self::assertSame('sonnet', $request->model);
        self::assertSame('medium', $request->effort);
        self::assertSame((string) $request->id, $audit->record('insights.analysis_started')->context['workRequestId']);
    }

    public function test_the_project_settings_give_the_model_and_effort_and_the_command_overrides_them(): void
    {
        $project = $this->scenarioProject('start-analysis-settings');
        $settings = self::getContainer()->get(UpdateAnalyticsSettingsHandler::class);
        self::assertInstanceOf(UpdateAnalyticsSettingsHandler::class, $settings);
        $settings(new UpdateAnalyticsSettingsCommand($project, 'opus', 'high', false));

        $fromSettings = $this->handler()(new StartAnalysisCommand($project, AnalysisTopic::Cost, MetricRange::All));
        $overridden = $this->handler()(new StartAnalysisCommand($project, AnalysisTopic::Time, MetricRange::All, model: 'haiku', effort: 'max'));

        self::assertSame(['opus', 'high'], [$fromSettings->model, $fromSettings->effort]);
        self::assertSame(['haiku', 'max'], [$overridden->model, $overridden->effort]);
    }

    public function test_a_question_is_trimmed_and_a_blank_one_is_dropped(): void
    {
        $project = $this->scenarioProject('start-analysis-question');

        $asked = $this->handler()(new StartAnalysisCommand($project, AnalysisTopic::Question, MetricRange::All, question: '  Why so slow?  '));
        $blank = $this->handler()(new StartAnalysisCommand($project, AnalysisTopic::Cost, MetricRange::All, question: '   '));

        self::assertSame('Why so slow?', $asked->question);
        self::assertNull($blank->question);
    }

    /** @return iterable<string, array{AnalysisTopic, ?string, ?string, ?string, array<string, string>}> */
    public static function refused(): iterable
    {
        yield 'a question that is too long' => [AnalysisTopic::Cost, str_repeat('a', 2001), null, null, ['question' => StartAnalysisHandler::QUESTION_TOO_LONG]];
        yield 'a question topic with no question' => [AnalysisTopic::Question, ' ', null, null, ['question' => StartAnalysisHandler::QUESTION_REQUIRED]];
        yield 'a malformed model' => [AnalysisTopic::Cost, null, 'two words', null, ['model' => StartAnalysisHandler::INVALID_MODEL]];
        yield 'an unknown effort' => [AnalysisTopic::Cost, null, null, 'extreme', ['effort' => StartAnalysisHandler::INVALID_EFFORT]];
    }

    /** @param array<string, string> $errors */
    #[DataProvider('refused')]
    public function test_a_malformed_command_is_refused_and_stores_nothing(AnalysisTopic $topic, ?string $question, ?string $model, ?string $effort, array $errors): void
    {
        $project = $this->scenarioProject('start-analysis-refused');

        try {
            $this->handler()(new StartAnalysisCommand($project, $topic, MetricRange::All, $question, $model, $effort));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }

        self::assertSame([], $this->analyses()->findBy(['project' => $project]));
    }

    public function test_a_work_request_that_bridge_refuses_fails_the_analysis(): void
    {
        $project = $this->scenarioProject('start-analysis-bridge-refuses');
        $blind = new OpenWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock(self::NOW),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            $this->service(WorkerRunRepository::class),
            new WorkSubjectHandlers([]),
        );
        $handler = new StartAnalysisHandler($this->em(), $blind, $this->service(AnalysisSettings::class), new MockClock(self::NOW), $this->service(Auditor::class));

        try {
            $handler(new StartAnalysisCommand($project, AnalysisTopic::Cost, MetricRange::All));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['subjectType' => OpenWorkRequestHandler::UNKNOWN_SUBJECT_TYPE], $e->errors);
        }

        $this->em()->clear();
        $analyses = $this->analyses()->findBy(['project' => (string) $project->id]);
        self::assertCount(1, $analyses);
        self::assertSame(AnalysisState::Failed, $analyses[0]->state);
        self::assertSame(StartAnalysisHandler::REQUEST_REFUSED, $analyses[0]->reason);
        self::assertNull($analyses[0]->workRequestId);
    }

    public function test_a_work_request_that_fails_to_open_fails_the_analysis_and_rethrows(): void
    {
        $project = $this->scenarioProject('start-analysis-bridge-fails');
        $broken = $this->createStub(WorkRequestRepository::class);
        $broken->method('lockLive')->willThrowException(new \RuntimeException('database gone'));
        $failing = new OpenWorkRequestHandler(
            $broken,
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock(self::NOW),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            $this->service(WorkerRunRepository::class),
            $this->service(WorkSubjectHandlers::class),
        );
        $handler = new StartAnalysisHandler($this->em(), $failing, $this->service(AnalysisSettings::class), new MockClock(self::NOW), $this->service(Auditor::class));

        try {
            $handler(new StartAnalysisCommand($project, AnalysisTopic::Cost, MetricRange::All));
            self::fail('Expected the failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('database gone', $e->getMessage());
        }

        self::assertFalse($this->em()->isOpen());
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT state, reason, finished_at, work_request_id FROM insights_analyses WHERE project_id = ?',
            [(string) $project->id],
        );
        self::assertSame([[
            'state' => AnalysisState::Failed->value,
            'reason' => StartAnalysisHandler::REQUEST_FAILED,
            'finished_at' => self::NOW,
            'work_request_id' => null,
        ]], $rows);
    }

    private function handler(): StartAnalysisHandler
    {
        return $this->service(StartAnalysisHandler::class);
    }

    private function analyses(): AnalysisRepository
    {
        return $this->service(AnalysisRepository::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
