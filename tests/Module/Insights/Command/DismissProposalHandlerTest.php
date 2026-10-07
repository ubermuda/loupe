<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\AcceptProposalHandler;
use App\Module\Insights\Command\DismissProposalCommand;
use App\Module\Insights\Command\DismissProposalHandler;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Repository\ProposalRepository;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DismissProposalHandlerTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_dismissal_keeps_its_trimmed_reason(): void
    {
        $proposal = $this->proposal('dismiss-proposal');

        $this->handler()(new DismissProposalCommand($proposal, '  We cache them already.  '));

        $stored = $this->stored($proposal);
        self::assertSame(ProposalState::Dismissed, $stored->state);
        self::assertSame('We cache them already.', $stored->dismissReason);
    }

    public function test_a_blank_reason_is_no_reason(): void
    {
        $proposal = $this->proposal('dismiss-proposal-blank');

        $this->handler()(new DismissProposalCommand($proposal, '   '));

        self::assertNull($this->stored($proposal)->dismissReason);
    }

    public function test_a_long_reason_is_refused(): void
    {
        $proposal = $this->proposal('dismiss-proposal-long');

        $this->assertRefused(['reason' => DismissProposalHandler::REASON_TOO_LONG], new DismissProposalCommand($proposal, str_repeat('r', 501)));
        self::assertSame(ProposalState::Proposed, $this->stored($proposal)->state);
    }

    public function test_a_proposal_that_is_no_longer_proposed_is_refused(): void
    {
        $proposal = $this->proposal('dismiss-proposal-created');
        $proposal->state = ProposalState::Created;
        $this->em()->flush();

        $this->assertRefused(['proposal' => AcceptProposalHandler::NOT_PROPOSED], new DismissProposalCommand($proposal));
        self::assertSame(ProposalState::Created, $this->stored($proposal)->state);
    }

    private function proposal(string $name): Proposal
    {
        $em = $this->em();

        return $this->seedProposal($em, $this->seedAnalysis($em, $this->scenarioProject($name), AnalysisState::Done));
    }

    private function stored(Proposal $proposal): Proposal
    {
        $this->em()->clear();
        $repository = self::getContainer()->get(ProposalRepository::class);
        self::assertInstanceOf(ProposalRepository::class, $repository);
        $stored = $repository->find($proposal->id);
        self::assertInstanceOf(Proposal::class, $stored);

        return $stored;
    }

    /** @param array<string, string> $errors */
    private function assertRefused(array $errors, DismissProposalCommand $command): void
    {
        try {
            $this->handler()($command);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    private function handler(): DismissProposalHandler
    {
        $handler = self::getContainer()->get(DismissProposalHandler::class);
        self::assertInstanceOf(DismissProposalHandler::class, $handler);

        return $handler;
    }
}
