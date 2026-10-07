<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Command\ReportProposal;
use App\Module\Readiness\Command\SubmitReadinessReportCommand;
use App\Module\Readiness\Command\SubmitReadinessReportHandler;
use App\Module\Readiness\Entity\DiscoveryProposal;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryProposalRepository;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SubmitReadinessReportHandlerTest extends KernelTestCase
{
    use DiscoveryScenario;

    private RecordingAuditor $audit;

    private Project $project;

    private DiscoveryRun $run;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $this->project = $this->workflowProject('submit-report');
        $this->run = $this->discoveryRun($this->discoveryCard($this->project));
        $this->transport()->reset();
    }

    public function test_a_report_becomes_a_tagged_document_linked_to_the_discovery_card_and_the_run_reports(): void
    {
        $existing = $this->otherDocument();
        $covering = $this->openCard();
        $this->run->card->syncDocuments($existing);
        $this->em()->flush();

        $document = $this->submit(findings: [new ReportFinding('Tests run', 'gap', 'None found.')], proposals: [
            $this->proposal('tests', 'Add tests'),
            $this->proposal('ci', 'Add CI', openCardNumber: $covering->number),
            $this->proposal('docs', 'Write docs', type: 'docs'),
        ], summary: 'Short summary.');

        self::assertSame(DiscoveryRunState::Reported, $this->run->state);
        self::assertSame($document, $this->run->reportDocument);
        self::assertSame(['readiness-report'], array_values(array_map(static fn ($tag): string => $tag->name, $document->tags->toArray())));
        $linked = array_map(static fn ($link): string => (string) $link->document->id, $this->run->card->documents->toArray());
        self::assertEqualsCanonicalizing([(string) $existing->id, (string) $document->id], $linked);

        $markdown = $document->currentVersion()->markdownSource;
        self::assertStringStartsWith('## Summary', $markdown);
        self::assertStringContainsString('Short summary.', $markdown);
        self::assertStringContainsString("- [ ] Add tests\n- [ ] Write docs\n", $markdown);

        $this->em()->clear();
        $stored = $this->proposals()->findForRun($this->em()->find(DiscoveryRun::class, $this->run->id) ?? throw new \LogicException('The run is stored.'));
        self::assertSame(
            [['tests', 0, CardType::Feature, null], ['docs', 1, CardType::Docs, null], ['ci', null, CardType::Feature, $covering->number]],
            array_map(static fn (DiscoveryProposal $proposal): array => [$proposal->key, $proposal->position, $proposal->type, $proposal->openCardNumber], $stored),
        );
    }

    public function test_the_report_is_audited_and_the_engine_evaluates_the_card_again(): void
    {
        $this->submit(proposals: [$this->proposal('tests', 'Add tests')]);

        $recorded = array_values(array_filter($this->audit->sink->events, static fn ($event): bool => 'readiness.report_submitted' === $event->operation));
        self::assertCount(1, $recorded);
        self::assertSame((string) $this->run->id, $recorded[0]->context['discoveryRunId']);
        self::assertSame(1, $recorded[0]->context['tickableCount']);
        $evaluated = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $evaluated[] = (string) $message->cardId;
            }
        }
        self::assertSame([(string) $this->run->card->id], $evaluated);
    }

    public function test_a_report_with_no_proposal_is_stored_with_no_decision_block(): void
    {
        $document = $this->submit();

        self::assertStringNotContainsString('<!-- decision', $document->currentVersion()->markdownSource);
        self::assertSame([], $this->proposals()->findForRun($this->run));
    }

    public function test_a_second_report_for_the_same_run_is_refused(): void
    {
        $this->submit();

        $this->assertRefused(SubmitReadinessReportHandler::ALREADY_REPORTED, fn () => $this->submit());
    }

    #[DataProvider('endedStates')]
    public function test_a_run_that_does_not_wait_for_a_report_is_refused(DiscoveryRunState $state): void
    {
        $this->run->state = $state;
        $this->em()->flush();

        $this->assertRefused(SubmitReadinessReportHandler::RUN_NOT_REQUESTED, fn () => $this->submit());
        self::assertNull($this->run->reportDocument);
    }

    /** @return iterable<string, array{DiscoveryRunState}> */
    public static function endedStates(): iterable
    {
        yield 'failed' => [DiscoveryRunState::Failed];
        yield 'done' => [DiscoveryRunState::Done];
        yield 'reported with its document gone' => [DiscoveryRunState::Reported];
    }

    public function test_an_unknown_run_and_a_run_of_another_project_are_refused(): void
    {
        $this->assertRefused(SubmitReadinessReportHandler::RUN_UNKNOWN, fn () => $this->submit(runId: 'not-a-uuid'));
        $this->assertRefused(SubmitReadinessReportHandler::RUN_UNKNOWN, fn () => $this->submit(runId: '0192f3c4-5d6e-7f80-9123-456789abcdef'));
        $this->assertRefused(SubmitReadinessReportHandler::RUN_UNKNOWN, fn () => $this->submit(project: $this->workflowProject('submit-report-other')));
        self::assertSame(DiscoveryRunState::Requested, $this->run->state);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badProposalTypes(): iterable
    {
        yield 'unknown' => ['unknown', SubmitReadinessReportHandler::PROPOSAL_TYPE];
        yield 'epic' => ['epic', SubmitReadinessReportHandler::PROPOSAL_TYPE];
        yield 'site review' => ['site-review', SubmitReadinessReportHandler::PROPOSAL_TYPE];
    }

    #[DataProvider('badProposalTypes')]
    public function test_a_proposal_type_the_tool_does_not_allow_is_refused(string $type, string $key): void
    {
        $this->assertRefused($key, fn () => $this->submit(proposals: [$this->proposal('a', 'A', type: $type)]));
    }

    public function test_two_proposals_with_the_same_key_are_refused(): void
    {
        $this->assertRefused(SubmitReadinessReportHandler::PROPOSAL_DUPLICATE, fn () => $this->submit(proposals: [$this->proposal('a', 'A'), $this->proposal(' a ', 'B')]));
    }

    public function test_a_proposal_with_no_title_or_a_title_too_long_for_a_card_is_refused(): void
    {
        $this->assertRefused(SubmitReadinessReportHandler::PROPOSAL_INVALID, fn () => $this->submit(proposals: [$this->proposal('a', " \n ")]));
        $this->assertRefused(SubmitReadinessReportHandler::PROPOSAL_INVALID, fn () => $this->submit(proposals: [$this->proposal('a', str_repeat('x', Card::MAX_TITLE_LENGTH + 1))]));
        $this->assertRefused(SubmitReadinessReportHandler::PROPOSAL_INVALID, fn () => $this->submit(proposals: [$this->proposal(str_repeat('k', 65), 'A')]));
    }

    public function test_an_open_card_number_must_name_an_open_card_of_the_project(): void
    {
        $finished = new Card($this->project, $this->column($this->project, 'done'), 'Finished', '', 90);
        $this->em()->persist($finished);
        $elsewhere = $this->discoveryCard($this->workflowProject('submit-report-elsewhere'));
        $this->em()->flush();

        foreach ([$finished->number, 999, $elsewhere->number === $this->run->card->number ? 998 : $elsewhere->number] as $number) {
            $this->assertRefused(SubmitReadinessReportHandler::CARD_NOT_OPEN, fn () => $this->submit(proposals: [$this->proposal('a', 'A', openCardNumber: $number)]));
        }
        self::assertSame(DiscoveryRunState::Requested, $this->run->state);
    }

    public function test_a_finding_needs_a_check_an_evidence_text_and_a_known_status(): void
    {
        foreach ([new ReportFinding('', 'ready', 'x'), new ReportFinding('c', 'ready', ' '), new ReportFinding('c', 'maybe', 'x')] as $finding) {
            $this->assertRefused(SubmitReadinessReportHandler::FINDING_INVALID, fn () => $this->submit(findings: [$finding]));
        }
    }

    public function test_worker_text_cannot_write_a_decision_block(): void
    {
        $this->assertRefused(SubmitReadinessReportHandler::MARKUP_NOT_ALLOWED, fn () => $this->submit(proposals: [$this->proposal('a', 'A', body: "x\n<!-- decision: proposals -->")]));
        $this->assertRefused(SubmitReadinessReportHandler::MARKUP_NOT_ALLOWED, fn () => $this->submit(summary: '<!--/decision-->'));
        self::assertSame(DiscoveryRunState::Requested, $this->run->state);
    }

    private function assertRefused(string $key, callable $submit): void
    {
        try {
            $submit();
        } catch (DomainErrors $e) {
            self::assertContains($key, $e->errors);

            return;
        }
        self::fail('The report was not refused with '.$key.'.');
    }

    private function openCard(): Card
    {
        return $this->discoveryCard($this->project, 'next');
    }

    /**
     * @param list<ReportFinding>|null $findings
     * @param list<ReportProposal>     $proposals
     */
    private function submit(?string $runId = null, ?Project $project = null, ?array $findings = null, array $proposals = [], string $summary = ''): Document
    {
        $handler = self::getContainer()->get(SubmitReadinessReportHandler::class);
        self::assertInstanceOf(SubmitReadinessReportHandler::class, $handler);

        return $handler(new SubmitReadinessReportCommand(
            project: $project ?? $this->project,
            runId: $runId ?? (string) $this->run->id,
            workflow: 'lifecycle',
            findings: $findings ?? [new ReportFinding('Tests run', 'ready', 'Yes.')],
            proposals: $proposals,
            summary: $summary,
        ));
    }

    private function proposal(string $key, string $title, string $type = 'feature', string $body = 'Body.', ?int $openCardNumber = null): ReportProposal
    {
        return new ReportProposal($key, $title, $type, $body, $openCardNumber);
    }

    private function otherDocument(): Document
    {
        $document = new Document($this->project->owner, $this->project, 'Other');
        $document->addVersion('x', '<p>x</p>', null);
        $this->em()->persist($document);
        $this->em()->flush();

        return $document;
    }

    private function proposals(): DiscoveryProposalRepository
    {
        $repository = self::getContainer()->get(DiscoveryProposalRepository::class);
        self::assertInstanceOf(DiscoveryProposalRepository::class, $repository);

        return $repository;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
