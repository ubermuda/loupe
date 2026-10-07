<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Command\ReportProposal;
use App\Module\Readiness\Command\SubmitReadinessReportCommand;
use App\Module\Readiness\Command\SubmitReadinessReportHandler;
use App\Module\Readiness\Entity\DiscoveryProposal;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryProposalRepository;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Tests\Module\Readiness\DiscoveryScenario;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateTickedCardsOnReviewSubmittedTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    private DiscoveryRun $run;

    private Document $report;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('ticked-cards');
        $this->bindLifecycle($this->project);
        $this->run = $this->discoveryRun($this->discoveryCard($this->project));
        $handler = self::getContainer()->get(SubmitReadinessReportHandler::class);
        self::assertInstanceOf(SubmitReadinessReportHandler::class, $handler);
        $this->report = $handler(new SubmitReadinessReportCommand(
            project: $this->project,
            runId: (string) $this->run->id,
            workflow: 'lifecycle',
            findings: [new ReportFinding('Tests run', 'gap', 'None.')],
            proposals: [
                new ReportProposal('tests', 'Add tests', 'feature', 'Write the first tests.'),
                new ReportProposal('docs', 'Write docs', 'docs', 'Write a README.'),
                new ReportProposal('ci', 'Add CI', 'tooling', 'Covered.', $this->discoveryCard($this->project, 'next')->number),
                new ReportProposal('lint', 'Add a linter', 'tooling', 'Lint it.'),
            ],
        ));
    }

    public function test_an_approval_creates_a_card_in_next_for_each_ticked_proposal_and_ends_the_run(): void
    {
        $this->tick(0, 2);

        $this->approve();

        $created = $this->createdCards();
        self::assertSame(['Add tests', 'Add a linter'], array_map(static fn (Card $card): string => $card->title, $created));
        foreach ($created as $card) {
            self::assertSame($this->column($this->project, 'next'), $card->column);
            self::assertSame(CardReporter::Agent, $card->reporter);
        }
        self::assertSame([CardType::Feature, CardType::Tooling], array_map(static fn (Card $card): CardType => $card->type, $created));
        self::assertSame('Write the first tests.', $created[0]->body);
        self::assertSame([(string) $created[0]->id, null, (string) $created[1]->id, null], array_map(static fn (DiscoveryProposal $proposal): ?string => $proposal->createdCardId?->toRfc4122(), $this->proposals()));
        self::assertTrue($this->run->card->column->terminal);
        self::assertSame([DiscoveryRunState::Done], [$this->run->state]);
        self::assertNotNull($this->run->endedAt);
        self::assertSame(DocumentStatus::Approved, $this->report->status);
    }

    public function test_an_approval_with_no_pick_creates_no_card_and_still_ends_the_run(): void
    {
        $this->approve();

        self::assertSame([], $this->createdCards());
        self::assertSame(DiscoveryRunState::Done, $this->run->state);
        self::assertTrue($this->run->card->column->terminal);
    }

    public function test_a_changes_requested_verdict_does_nothing(): void
    {
        $this->tick(0);

        $this->submitVerdict('changes-requested', 'Not yet.');

        self::assertSame([], $this->createdCards());
        self::assertSame(DiscoveryRunState::Reported, $this->run->state);
        self::assertFalse($this->run->card->column->terminal);
    }

    public function test_a_second_approval_creates_no_duplicate_card(): void
    {
        $this->tick(0, 2);
        $review = $this->approve();
        $first = $this->createdCards();

        $this->dispatch($review);
        self::assertSame($first, $this->createdCards());

        $this->run->state = DiscoveryRunState::Reported;
        $this->em()->flush();
        $this->dispatch($review);
        self::assertSame($first, $this->createdCards());
    }

    public function test_a_pick_whose_label_no_longer_matches_its_proposal_is_skipped(): void
    {
        $this->tick(0);
        $this->em()->persist(new DecisionSelection($this->report, 'proposals', 1, 'Something else', 1));
        $this->em()->flush();

        $this->approve();

        self::assertSame(['Add tests'], array_map(static fn (Card $card): string => $card->title, $this->createdCards()));
    }

    public function test_a_pick_of_another_decision_is_ignored(): void
    {
        $this->em()->persist(new DecisionSelection($this->report, 'other', 0, 'Add tests', 1));
        $this->em()->flush();

        $this->approve();

        self::assertSame([], $this->createdCards());
    }

    public function test_a_deleted_next_column_sends_the_cards_to_the_backlog(): void
    {
        $links = self::getContainer()->get(WorkflowSlotLinkRepository::class);
        self::assertInstanceOf(WorkflowSlotLinkRepository::class, $links);
        $link = $links->findOneBy(['project' => $this->project, 'slotKey' => 'next']);
        self::assertNotNull($link);
        $link->column = null;
        $this->em()->flush();
        $this->tick(1);

        $this->approve();

        $created = $this->createdCards();
        self::assertCount(1, $created);
        self::assertTrue($created[0]->column->backlog);
    }

    public function test_a_project_with_no_next_slot_sends_the_cards_to_the_backlog(): void
    {
        $project = $this->workflowProject('ticked-cards-no-slot');
        $run = $this->discoveryRun($this->discoveryCard($project));
        $handler = self::getContainer()->get(SubmitReadinessReportHandler::class);
        self::assertInstanceOf(SubmitReadinessReportHandler::class, $handler);
        $report = $handler(new SubmitReadinessReportCommand($project, (string) $run->id, 'none', [], [new ReportProposal('a', 'Add a thing', 'idea', 'Do it.')]));
        $this->em()->persist(new DecisionSelection($report, 'proposals', 0, 'Add a thing', 1));
        $this->em()->flush();

        $this->approve($report, $project);

        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        self::assertTrue($cards->findOneBy(['project' => $project, 'title' => 'Add a thing'])?->column->backlog);
    }

    public function test_a_document_that_is_not_a_report_is_left_alone(): void
    {
        $other = new Document($this->project->owner, $this->project, 'Plain');
        $other->addVersion('x', '<p>x</p>');
        $this->em()->persist($other);
        $this->em()->flush();

        $this->approve($other);

        self::assertSame(DiscoveryRunState::Reported, $this->run->state);
    }

    private function tick(int ...$positions): void
    {
        $titles = ['Add tests', 'Write docs', 'Add a linter'];
        foreach ($positions as $position) {
            $this->em()->persist(new DecisionSelection($this->report, 'proposals', $position, $titles[$position], 1));
        }
        $this->em()->flush();
    }

    private function approve(?Document $document = null, ?Project $project = null): Review
    {
        return $this->submitVerdict('approved', null, $document, $project);
    }

    private function submitVerdict(string $verdict, ?string $note, ?Document $document = null, ?Project $project = null): Review
    {
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);

        return $handler(new SubmitReviewCommand(($project ?? $this->project)->owner, $document ?? $this->report, $verdict, 1, $note));
    }

    private function dispatch(Review $review): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->dispatch(new ReviewSubmitted($review));
    }

    /** @return list<Card> the cards the proposals made, in proposal order */
    private function createdCards(): array
    {
        $cards = [];
        foreach ($this->proposals() as $proposal) {
            if (null !== $proposal->createdCardId) {
                $cards[] = $this->em()->find(Card::class, $proposal->createdCardId) ?? throw new \LogicException('The card is stored.');
            }
        }

        return $cards;
    }

    /** @return list<DiscoveryProposal> */
    private function proposals(): array
    {
        $repository = self::getContainer()->get(DiscoveryProposalRepository::class);
        self::assertInstanceOf(DiscoveryProposalRepository::class, $repository);

        return $repository->findForRun($this->run);
    }
}
