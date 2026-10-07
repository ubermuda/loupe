<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Insights\Command\AcceptProposalCommand;
use App\Module\Insights\Command\AcceptProposalHandler;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Proposal\ProposalCardCreatorInterface;
use App\Module\Insights\Repository\ProposalRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Insights\InsightsScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;

final class AcceptProposalHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_an_accepted_proposal_becomes_a_backlog_card_that_links_the_report(): void
    {
        $em = $this->em();
        [$proposal, $documentId] = $this->reportedProposal($em, 'accept-proposal');

        $accepted = $this->handler()(new AcceptProposalCommand($proposal));

        $em->clear();
        $stored = $this->service(ProposalRepository::class)->find($proposal->id);
        self::assertInstanceOf(Proposal::class, $stored);
        self::assertSame(ProposalState::Created, $stored->state);
        self::assertNotNull($stored->cardId);
        self::assertSame((string) $accepted->cardId, (string) $stored->cardId);
        $card = $this->service(CardRepository::class)->find($stored->cardId);
        self::assertInstanceOf(Card::class, $card);
        self::assertSame('Cache the dependencies', $card->title);
        self::assertSame('Each run installs them again.', $card->body);
        self::assertSame(CardType::Feature, $card->type);
        self::assertSame('backlog', $card->column->slug);
        self::assertSame([$documentId], array_map(static fn (CardDocument $link): string => (string) $link->document->id, $card->documents->toArray()));
    }

    public function test_a_proposal_accepted_once_refuses_a_second_accept(): void
    {
        $em = $this->em();
        [$proposal] = $this->reportedProposal($em, 'accept-proposal-twice');
        $this->handler()(new AcceptProposalCommand($proposal));

        $this->assertRefused(['proposal' => AcceptProposalHandler::NOT_PROPOSED], $proposal);
        self::assertSame(1, $this->countCards($em, $proposal));
    }

    public function test_a_dismissed_proposal_refuses_an_accept(): void
    {
        $em = $this->em();
        [$proposal] = $this->reportedProposal($em, 'accept-proposal-dismissed');
        $proposal->state = ProposalState::Dismissed;
        $em->flush();

        $this->assertRefused(['proposal' => AcceptProposalHandler::NOT_PROPOSED], $proposal);
    }

    public function test_a_bucket_rule_is_refused_for_now(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('accept-proposal-bucket');
        $proposal = $this->seedProposal($em, $this->seedAnalysis($em, $project, AnalysisState::Done), ProposalKind::BucketRule);

        $this->assertRefused(['proposal' => AcceptProposalHandler::BUCKET_RULE_UNSUPPORTED], $proposal);
        self::assertSame(ProposalState::Proposed, $proposal->state);
    }

    public function test_a_card_the_board_refuses_puts_the_proposal_back(): void
    {
        $em = $this->em();
        [$proposal] = $this->reportedProposal($em, 'accept-proposal-board-refuses');
        $creator = $this->createStub(ProposalCardCreatorInterface::class);
        $creator->method('createBacklogCard')->willThrowException(new DomainErrors(['title' => 'board.card.error.title_blank']));
        $handler = new AcceptProposalHandler($this->service(ProposalRepository::class), $creator, $em, $this->service(Auditor::class));

        try {
            $handler(new AcceptProposalCommand($proposal));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['title' => 'board.card.error.title_blank'], $e->errors);
        }

        $em->clear();
        $stored = $this->service(ProposalRepository::class)->find($proposal->id);
        self::assertInstanceOf(Proposal::class, $stored);
        self::assertSame(ProposalState::Proposed, $stored->state);
        self::assertNull($stored->cardId);
    }

    /** @return array{Proposal, string} */
    private function reportedProposal(EntityManagerInterface $em, string $name): array
    {
        $project = $this->scenarioProject($name);
        $this->seedColumns($project);
        $em->flush();
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Done);
        $document = $this->seedDocument($em, $project);
        $analysis->documentId = $document->id;
        $em->flush();

        return [$this->seedProposal($em, $analysis), (string) $document->id];
    }

    private function countCards(EntityManagerInterface $em, Proposal $proposal): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = ?', [(string) $proposal->analysis->project->id]);
    }

    /** @param array<string, string> $errors */
    private function assertRefused(array $errors, Proposal $proposal): void
    {
        try {
            $this->handler()(new AcceptProposalCommand($proposal));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    private function handler(): AcceptProposalHandler
    {
        return $this->service(AcceptProposalHandler::class);
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
