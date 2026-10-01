<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\MatchEpicPullRequestDraftCommand;
use App\Module\Board\Command\MatchEpicPullRequestDraftHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Messenger\SyncEpicPullRequestDraft;
use App\Module\Board\Messenger\SyncEpicPullRequestDraftHandler;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\EpicPullRequestWrites;
use App\Module\Board\Service\LifecycleStages;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestStateWriters;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\EpicPullRequestScenario;
use App\Tests\Module\Board\FakePullRequestStateWriter;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

final class MatchEpicPullRequestDraftHandlerTest extends KernelTestCase
{
    use EpicPullRequestScenario;

    private EntityManagerInterface $em;
    private FakePullRequestStateWriter $writer;
    private RecordingLogger $logger;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->writer = new FakePullRequestStateWriter();
        $this->logger = new RecordingLogger();

        $this->enableBoard();
        $this->project = $this->reviewProject('match-draft');
    }

    public function test_an_epic_in_review_marks_each_pull_request_ready(): void
    {
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 7);
        $this->linkPullRequest($epic, 8);

        $this->handle($epic);

        self::assertEqualsCanonicalizing([['setDraft', 7, false], ['setDraft', 8, false]], $this->writer->calls);
    }

    public function test_an_epic_in_implementation_converts_its_pull_request_to_draft(): void
    {
        $epic = $this->card($this->project, 'implementation');
        $this->linkPullRequest($epic, 7);

        $this->handle($epic);

        self::assertSame([['setDraft', 7, true]], $this->writer->calls);
    }

    public function test_the_column_the_card_holds_when_the_message_runs_decides(): void
    {
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 7);
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET column_id = ? WHERE id = ?',
            [(string) $this->column($this->project, 'implementation')->id, (string) $epic->id],
        );

        $this->handle($epic);

        self::assertSame([['setDraft', 7, true]], $this->writer->calls);
    }

    public function test_an_epic_in_another_column_writes_nothing(): void
    {
        foreach (['next', 'backlog', 'done'] as $column) {
            $epic = $this->card($this->project, $column);
            $this->linkPullRequest($epic, 7 + $this->cardNumber);

            $this->handle($epic);
        }

        self::assertSame([], $this->writer->calls);
    }

    public function test_a_review_column_marked_terminal_writes_nothing(): void
    {
        $this->column($this->project, 'in-review')->terminal = true;
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 7);

        $this->handle($epic);

        self::assertSame([], $this->writer->calls);
    }

    public function test_a_feature_writes_nothing(): void
    {
        $card = $this->card($this->project, 'in-review', CardType::Feature);
        $this->linkPullRequest($card, 7);

        $this->handle($card);

        self::assertSame([], $this->writer->calls);
    }

    public function test_a_card_that_is_gone_writes_nothing(): void
    {
        $this->handler()(new MatchEpicPullRequestDraftCommand(Uuid::v7()));

        self::assertSame([], $this->writer->calls);
    }

    public function test_nothing_is_written_while_the_board_automation_is_off(): void
    {
        $this->em->persist(new BoardAutomationSettings($this->project, enabled: false));
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 7);

        $this->handle($epic);

        self::assertSame([], $this->writer->calls);
    }

    public function test_nothing_is_written_while_the_board_is_off(): void
    {
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 7);
        $this->disableBoard();
        $board = self::getContainer()->get(BoardAvailability::class);
        self::assertInstanceOf(BoardAvailability::class, $board);

        new SyncEpicPullRequestDraftHandler($this->handler(), $board)(new SyncEpicPullRequestDraft($this->cardId($epic)));

        self::assertSame([], $this->writer->calls);
    }

    public function test_a_link_with_no_forge_row_or_no_parse_is_skipped(): void
    {
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 6, tracked: false);
        $this->em->persist(new CardPullRequest($epic, 'https://git.example.com/acme/widgets/merge/9'));
        $this->linkPullRequest($epic, 7);

        $this->handle($epic);

        self::assertSame([['setDraft', 7, false]], $this->writer->calls);
    }

    public function test_a_permanent_failure_is_logged_and_the_next_pull_request_is_written(): void
    {
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 7);
        $this->linkPullRequest($epic, 8);
        $this->writer->failure = new PullRequestWriteFailed('permission', permanent: true);

        $this->handle($epic);

        self::assertCount(2, $this->writer->calls);
        $warnings = array_values(array_filter($this->logger->records, static fn (array $record): bool => LogLevel::WARNING === $record['level']));
        self::assertCount(2, $warnings);
        self::assertSame('board.epic_pull_request_write_failed', $warnings[0]['message']);
        self::assertSame([
            'cardId' => (string) $epic->id,
            'projectId' => (string) $this->project->id,
            'action' => 'ready',
            'forge' => 'github',
            'repository' => 'acme/widgets',
            'pullRequestNumber' => $this->writer->calls[0][1],
            'cause' => 'permission',
        ], $warnings[0]['context']);
    }

    public function test_a_transient_failure_rethrows(): void
    {
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 7);
        $failure = new PullRequestWriteFailed('api_failed_http_status_502', permanent: false);
        $this->writer->failure = $failure;

        try {
            $this->handle($epic);
            self::fail('Expected the transient failure to propagate.');
        } catch (PullRequestWriteFailed $e) {
            self::assertSame($failure, $e);
        }
    }

    public function test_a_rate_limit_asks_messenger_to_wait_within_its_retry_budget(): void
    {
        $epic = $this->card($this->project, 'in-review');
        $this->linkPullRequest($epic, 7);
        $failure = new PullRequestWriteFailed('api_failed_rate_limited', permanent: false, retryAfterSeconds: 172_800);
        $this->writer->failure = $failure;

        try {
            $this->handle($epic);
            self::fail('Expected the rate limit to propagate.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(86_400_000, $e->getRetryDelay());
            self::assertFalse($e->forceRetry());
            self::assertSame($failure, $e->getPrevious());
        }
    }

    private function handle(Card $card): void
    {
        $this->em->flush();
        $this->handler()(new MatchEpicPullRequestDraftCommand($this->cardId($card)));
    }

    private function handler(): MatchEpicPullRequestDraftHandler
    {
        $container = self::getContainer();
        $cards = $container->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $automation = $container->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);

        return new MatchEpicPullRequestDraftHandler($cards, $automation, $this->writes());
    }

    private function writes(): EpicPullRequestWrites
    {
        $container = self::getContainer();
        $links = $container->get(CardPullRequestRepository::class);
        self::assertInstanceOf(CardPullRequestRepository::class, $links);
        $pullRequests = $container->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $pullRequests);
        $stages = $container->get(LifecycleStages::class);
        self::assertInstanceOf(LifecycleStages::class, $stages);

        return new EpicPullRequestWrites($links, $pullRequests, new PullRequestStateWriters([$this->writer]), $stages, $this->logger);
    }
}
