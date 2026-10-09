<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\ReportAnalysisCommand;
use App\Module\Insights\Command\ReportAnalysisHandler;
use App\Module\Insights\Command\ReportedProposal;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\ProposalRepository;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ReportAnalysisHandlerTest extends KernelTestCase
{
    use InsightsScenario;

    private const string NOW = '2026-10-07 12:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    public function test_a_report_completes_the_analysis_and_stores_its_proposals_in_order(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('report-analysis');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $document = $this->seedDocument($em, $project);

        $this->handler()(new ReportAnalysisCommand($project, (string) $analysis->id, (string) $document->id, [
            new ReportedProposal('card', ' Cache the dependencies ', 'Each run installs them again.', ['files' => ['composer.lock']], ' About $4 a week '),
            new ReportedProposal('bucket-rule', 'Put lint runs in their own bucket', 'They skew the median.', ['pattern' => 'Bash:lint*', 'bucket' => 'lint']),
        ]));

        $em->clear();
        $stored = $this->service(AnalysisRepository::class)->find($analysis->id);
        self::assertInstanceOf(Analysis::class, $stored);
        self::assertSame(AnalysisState::Done, $stored->state);
        self::assertSame((string) $document->id, (string) $stored->documentId);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $stored->finishedAt);
        $proposals = $this->service(ProposalRepository::class)->findByAnalysis($stored);
        self::assertSame([ProposalKind::Card, ProposalKind::BucketRule], array_map(static fn ($p) => $p->kind, $proposals));
        self::assertSame('Cache the dependencies', $proposals[0]->title);
        self::assertSame(['files' => ['composer.lock']], $proposals[0]->payload);
        self::assertSame('About $4 a week', $proposals[0]->estimatedSaving);
        self::assertSame(ProposalState::Proposed, $proposals[0]->state);
        self::assertSame(['pattern' => 'Bash:lint*', 'bucket' => 'lint'], $proposals[1]->payload);
        self::assertNull($proposals[1]->estimatedSaving);
        self::assertSame([0, 1], array_map(static fn ($p) => $p->position, $proposals));
    }

    public function test_a_paused_analysis_takes_a_report_with_no_proposals(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('report-analysis-paused');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Paused);
        $document = $this->seedDocument($em, $project);

        $reported = $this->handler()(new ReportAnalysisCommand($project, (string) $analysis->id, (string) $document->id, []));

        self::assertSame(AnalysisState::Done, $reported->state);
    }

    /** @return iterable<string, array{AnalysisState}> */
    public static function finishedStates(): iterable
    {
        yield 'done' => [AnalysisState::Done];
        yield 'failed' => [AnalysisState::Failed];
    }

    #[DataProvider('finishedStates')]
    public function test_a_finished_analysis_refuses_a_report(AnalysisState $state): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('report-analysis-finished');
        $analysis = $this->seedAnalysis($em, $project, $state);
        $document = $this->seedDocument($em, $project);

        $this->assertRefused(['analysisId' => ReportAnalysisHandler::FINISHED], new ReportAnalysisCommand($project, (string) $analysis->id, (string) $document->id, []));
    }

    public function test_an_analysis_of_another_project_is_unknown(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('report-analysis-scope');
        $other = $this->scenarioProject('report-analysis-scope-other');
        $analysis = $this->seedAnalysis($em, $other);
        $document = $this->seedDocument($em, $project);

        $this->assertRefused(['analysisId' => ReportAnalysisHandler::UNKNOWN_ANALYSIS], new ReportAnalysisCommand($project, (string) $analysis->id, (string) $document->id, []));
        $this->assertRefused(['analysisId' => ReportAnalysisHandler::UNKNOWN_ANALYSIS], new ReportAnalysisCommand($project, 'not-a-uuid', (string) $document->id, []));
    }

    public function test_a_document_must_belong_to_the_project(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('report-analysis-document');
        $other = $this->scenarioProject('report-analysis-document-other');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $foreign = $this->seedDocument($em, $other);

        $this->assertRefused(['documentId' => ReportAnalysisHandler::UNKNOWN_DOCUMENT], new ReportAnalysisCommand($project, (string) $analysis->id, (string) $foreign->id, []));
        $this->assertRefused(['documentId' => ReportAnalysisHandler::UNKNOWN_DOCUMENT], new ReportAnalysisCommand($project, (string) $analysis->id, (string) Uuid::v7(), []));
        $this->assertRefused(['documentId' => ReportAnalysisHandler::UNKNOWN_DOCUMENT], new ReportAnalysisCommand($project, (string) $analysis->id, 'nope', []));
    }

    /** @return iterable<string, array{list<ReportedProposal>, string}> */
    public static function malformedProposals(): iterable
    {
        $valid = new ReportedProposal('card', 'Title', 'Body');
        yield 'too many' => [array_fill(0, 21, $valid), ReportAnalysisHandler::TOO_MANY_PROPOSALS];
        yield 'an unknown kind' => [[new ReportedProposal('epic', 'Title', 'Body')], ReportAnalysisHandler::INVALID_KIND];
        yield 'a blank title' => [[new ReportedProposal('card', '  ', 'Body')], ReportAnalysisHandler::TITLE_BLANK];
        yield 'a long title' => [[new ReportedProposal('card', str_repeat('t', 201), 'Body')], ReportAnalysisHandler::TITLE_TOO_LONG];
        yield 'a blank body' => [[$valid, new ReportedProposal('card', 'Title', ' ')], ReportAnalysisHandler::BODY_BLANK];
        yield 'a bucket rule with no payload' => [[new ReportedProposal('bucket-rule', 'Title', 'Body')], ReportAnalysisHandler::BUCKET_RULE_INVALID];
        yield 'a bucket rule with a bad bucket name' => [[new ReportedProposal('bucket-rule', 'Title', 'Body', ['pattern' => 'Bash:lint*', 'bucket' => 'Lint Runs'])], ReportAnalysisHandler::BUCKET_RULE_INVALID];
        yield 'a bucket rule with a blank pattern' => [[new ReportedProposal('bucket-rule', 'Title', 'Body', ['pattern' => ' ', 'bucket' => 'lint'])], ReportAnalysisHandler::BUCKET_RULE_INVALID];
        yield 'a bucket rule with a non-string pattern' => [[new ReportedProposal('bucket-rule', 'Title', 'Body', ['pattern' => ['x'], 'bucket' => 'lint'])], ReportAnalysisHandler::BUCKET_RULE_INVALID];
        yield 'a long saving' => [[new ReportedProposal('card', 'Title', 'Body', null, str_repeat('s', 201))], ReportAnalysisHandler::SAVING_TOO_LONG];
    }

    /** @param list<ReportedProposal> $proposals */
    #[DataProvider('malformedProposals')]
    public function test_a_malformed_proposal_refuses_the_whole_report(array $proposals, string $error): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('report-analysis-proposals');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $document = $this->seedDocument($em, $project);

        $this->assertRefused(['proposals' => $error], new ReportAnalysisCommand($project, (string) $analysis->id, (string) $document->id, $proposals));

        $em->clear();
        $stored = $this->service(AnalysisRepository::class)->find($analysis->id);
        self::assertInstanceOf(Analysis::class, $stored);
        self::assertSame(AnalysisState::Running, $stored->state);
        self::assertSame([], $this->service(ProposalRepository::class)->findByAnalysis($stored));
    }

    /** @param array<string, string> $errors */
    private function assertRefused(array $errors, ReportAnalysisCommand $command): void
    {
        try {
            $this->handler()($command);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    private function handler(): ReportAnalysisHandler
    {
        return $this->service(ReportAnalysisHandler::class);
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
