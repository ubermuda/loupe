<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
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
