<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Mcp\BoardSubjectResolver;
use App\Module\Project\Entity\Project;
use App\Security\McpBoundProjectVoter;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * The resolver leaves the scope check to McpBoundProjectVoter, so a card in
 * another project is refused by a vote that records the attempt. A lookup
 * scoped to the bound project would refuse it as silently as a card that does
 * not exist.
 */
final class BoardSubjectResolverTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private BoardSubjectResolver $resolver;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $resolver = self::getContainer()->get(BoardSubjectResolver::class);
        self::assertInstanceOf(BoardSubjectResolver::class, $resolver);
        $this->resolver = $resolver;
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature, CardPriority::Medium));
    }

    /**
     * The tool description asks an agent not to claim it. That is a request, and
     * a value an MCP client can send is a constraint or it is nothing.
     */
    public function test_an_agent_cannot_claim_the_reviewer_reporter(): void
    {
        // Guard: the two an agent may claim still resolve, so the refusal below
        // is about this value rather than about reporters being refused wholesale.
        self::assertSame(CardOrigin::Human, $this->resolver->requireClaimedReporter('human'));
        self::assertSame(CardOrigin::Agent, $this->resolver->requireClaimedReporter('agent'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown reporter "reviewer". Use one of: human, agent.');
        $this->resolver->requireClaimedReporter('reviewer');
    }

    /** A filter matches a reporter rather than claiming one, so it reads the widget's cards too. */
    public function test_a_filter_reads_every_reporter_including_reviewer(): void
    {
        self::assertSame(CardOrigin::Reviewer, $this->resolver->requireReporter('reviewer'));
        self::assertSame(CardOrigin::Human, $this->resolver->requireReporter('human'));
        self::assertSame(CardOrigin::Agent, $this->resolver->requireReporter('agent'));
    }

    public function test_a_filter_refuses_a_reporter_that_is_not_a_value(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown reporter "robot". Use one of: human, agent, reviewer.');
        $this->resolver->requireReporter('robot');
    }

    public function test_a_card_of_the_bound_project_resolves(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('resolver-mine');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->cardIn($project);

        self::assertSame($card, $this->resolver->requireCard((string) $card->id, McpBoundProjectVoter::CARD_READ));
    }

    public function test_a_card_in_another_project_is_refused_and_recorded(): void
    {
        $this->enableBoard();
        $theirs = $this->makeProject('resolver-theirs');
        $card = $this->cardIn($theirs);

        $mine = $this->makeProject('resolver-mine');
        $this->actAsMcpTokenBoundTo($mine);
        $this->audit->forget();

        try {
            $this->resolver->requireCard((string) $card->id, McpBoundProjectVoter::CARD_WRITE);
            self::fail('A card outside the bound project must not resolve.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        $record = $this->audit->record('board.mcp_access_denied');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::CARD_WRITE,
            'subjectId' => (string) $card->id,
            'subjectProjectId' => (string) $theirs->id,
            'boundProjectId' => (string) $mine->id,
        ], $record->context);
    }

    public function test_a_card_that_does_not_exist_is_refused_without_a_record(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('resolver-missing'));
        $this->audit->forget();

        try {
            $this->resolver->requireCard('01920000-0000-7000-8000-000000000000', McpBoundProjectVoter::CARD_READ);
            self::fail('An unknown card must not resolve.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        // The message is the same as for a card in another project, so a tool
        // cannot probe what exists outside its own. Nothing was voted on, so
        // the trail carries no refusal.
        self::assertSame([], $this->audit->operations());
    }
}
