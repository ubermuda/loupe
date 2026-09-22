<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Mcp\BoardSubjectResolver;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\McpBoundProjectVoter;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
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

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }

    /**
     * The tool description asks an agent not to claim it. That is a request, and
     * a value an MCP client can send is a constraint or it is nothing.
     */
    public function test_an_agent_cannot_claim_the_reviewer_reporter(): void
    {
        // Guard: the two an agent may claim still resolve, so the refusal below
        // is about this value rather than about reporters being refused wholesale.
        self::assertSame(CardReporter::Human, $this->resolver->requireClaimedReporter('human'));
        self::assertSame(CardReporter::Agent, $this->resolver->requireClaimedReporter('agent'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown reporter "reviewer". Use one of: human, agent.');
        $this->resolver->requireClaimedReporter('reviewer');
    }

    /** A filter matches a reporter rather than claiming one, so it reads the widget's cards too. */
    public function test_a_filter_reads_every_reporter_including_reviewer(): void
    {
        self::assertSame(CardReporter::Reviewer, $this->resolver->requireReporter('reviewer'));
        self::assertSame(CardReporter::Human, $this->resolver->requireReporter('human'));
        self::assertSame(CardReporter::Agent, $this->resolver->requireReporter('agent'));
    }

    public function test_a_filter_refuses_a_reporter_that_is_not_a_value(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown reporter "robot". Use one of: human, agent, reviewer, system.');
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

    public function test_a_card_resolves_by_its_number_inside_the_bound_project(): void
    {
        $this->enableBoard();
        $theirs = $this->makeProject('resolver-number-theirs');
        $theirCard = $this->cardIn($theirs);
        $mine = $this->makeProject('resolver-number-mine');
        $myCard = $this->cardIn($mine);
        self::assertSame($theirCard->number, $myCard->number);

        $this->actAsMcpTokenBoundTo($mine);

        self::assertSame($myCard, $this->resolver->requireCardByIdOrNumber(null, $myCard->number, McpBoundProjectVoter::CARD_READ));
    }

    public function test_a_card_id_still_resolves_through_the_new_entry_point(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('resolver-by-id');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->cardIn($project);

        self::assertSame($card, $this->resolver->requireCardByIdOrNumber((string) $card->id, null, McpBoundProjectVoter::CARD_WRITE));
    }

    /** @return iterable<string, array{?string, ?int, string}> */
    public static function refusedHandles(): iterable
    {
        yield 'both' => ['01920000-0000-7000-8000-000000000000', 1, 'Pass cardId or number, not both.'];
        yield 'neither' => [null, null, 'Pass cardId or number.'];
        yield 'zero' => [null, 0, 'Card numbers count from 1, so 0 is not a card number.'];
        yield 'negative' => [null, -3, 'Card numbers count from 1, so -3 is not a card number.'];
        yield 'unknown number' => [null, 42, 'This project has no card 42.'];
    }

    #[DataProvider('refusedHandles')]
    public function test_a_bad_handle_is_refused(?string $cardId, ?int $number, string $message): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('resolver-refused'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        $this->resolver->requireCardByIdOrNumber($cardId, $number, McpBoundProjectVoter::CARD_READ);
    }

    public function test_a_number_passed_as_a_card_id_is_refused_with_a_hint(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('resolver-digits'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"42" is not a valid card ID. To read a card by its number, pass number instead.');
        $this->resolver->requireCardByIdOrNumber('42', null, McpBoundProjectVoter::CARD_READ);
    }

    public function test_a_malformed_id_that_is_not_digits_gets_no_hint(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('resolver-no-hint'));

        try {
            $this->resolver->requireCard('card-42', McpBoundProjectVoter::CARD_READ);
            self::fail('A malformed id must not resolve.');
        } catch (ToolCallException $e) {
            self::assertSame('"card-42" is not a valid card ID.', $e->getMessage());
        }
    }

    public function test_a_refused_number_vote_is_reported_as_a_missing_card(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('resolver-number-refused');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->cardIn($project);

        $projects = self::getContainer()->get(AuthenticatedProjectResolver::class);
        self::assertInstanceOf(AuthenticatedProjectResolver::class, $projects);
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $columns = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $columns);

        $resolver = new BoardSubjectResolver($projects, $cards, $columns, $this->refusingAuthorization());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(\sprintf('This project has no card %d.', $card->number));
        $resolver->requireCardByIdOrNumber(null, $card->number, McpBoundProjectVoter::CARD_READ);
    }

    private function refusingAuthorization(): AuthorizationCheckerInterface
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects($this->once())->method('isGranted')->willReturn(false);

        return $authorization;
    }
}
