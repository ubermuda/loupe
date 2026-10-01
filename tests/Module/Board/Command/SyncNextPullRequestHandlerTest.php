<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\SyncNextPullRequestCommand;
use App\Module\Board\Command\SyncNextPullRequestHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Messenger\SyncNextPullRequest;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\SyncLine;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestBranchUpdaters;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Module\Board\FakePullRequestBranchUpdater;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SyncNextPullRequestHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private MockClock $clock;
    private FakePullRequestBranchUpdater $updater;
    private Project $project;
    private int $cardNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->clock = new MockClock('2026-09-30 12:00:00');
        $this->updater = new FakePullRequestBranchUpdater();

        $this->enableBoard();
        $this->project = $this->makeProject('sync-next');
        $this->settings(enabled: true, syncBehind: true);
    }

    public function test_it_asks_the_forge_to_sync_the_next_pull_request_and_leaves_its_cards_to_the_read_that_confirms_it(): void
    {
        $pullRequest = $this->behind(5);
        $card = $this->linkedCard(5);

        $this->handle();

        self::assertCount(1, $this->updater->updates);
        self::assertSame($pullRequest, $this->updater->updates[0][0]);
        self::assertSame('head005', $this->updater->updates[0][1]);
        $this->em->refresh($pullRequest);
        self::assertSame('head005', $pullRequest->syncFromSha);
        self::assertEquals($this->clock->now(), $pullRequest->syncRequestedAt);
        self::assertNull($pullRequest->syncFailedReason);

        self::assertNull($this->automationOf($card));
        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_marker_queues_a_pass_for_after_it_expires(): void
    {
        $this->behind(5);

        $this->handle();

        $passes = $this->queuedPasses();
        self::assertCount(1, $passes);
        $message = $passes[0]->getMessage();
        self::assertInstanceOf(SyncNextPullRequest::class, $message);
        self::assertEquals($this->project->id, $message->projectId);
        $delay = $passes[0]->last(DelayStamp::class);
        self::assertNotNull($delay);
        self::assertSame((SyncLine::MARKER_LIFETIME_SECONDS + 30) * 1000, $delay->getDelay());
    }

    /** @return iterable<string, array{string}> */
    public static function approvalLosses(): iterable
    {
        yield 'the approval is gone' => ['UPDATE forge_pull_requests SET approval_id = NULL WHERE id = :id'];
        yield 'the approval covers another head' => ["UPDATE forge_pull_requests SET covered_sha = 'other01' WHERE id = :id"];
        yield 'the pull request became a draft' => ['UPDATE forge_pull_requests SET draft = true WHERE id = :id'];
        yield 'changes were requested' => ["UPDATE forge_pull_requests SET review = 'changes-requested' WHERE id = :id"];
    }

    #[DataProvider('approvalLosses')]
    public function test_a_pull_request_that_left_the_line_after_the_read_is_not_marked(string $change): void
    {
        $pullRequest = $this->behind(5);
        // The line reads the row this entity manager holds, and only the lock reads the database.
        $this->em->getConnection()->executeStatement($change, ['id' => (string) $pullRequest->id]);

        $this->handle();

        self::assertSame([], $this->updater->updates);
        self::assertSame([], $this->queuedPasses());
        $this->em->refresh($pullRequest);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_a_permanent_failure_on_the_same_head_records_the_cause(): void
    {
        $pullRequest = $this->behind(5);
        $this->updater->failure = new PullRequestSyncFailed('refused', permanent: true);

        $this->handle();

        $this->em->refresh($pullRequest);
        self::assertSame('refused', $pullRequest->syncFailedReason);
        self::assertNull($pullRequest->syncFromSha);
        self::assertNull($pullRequest->syncRequestedAt);
    }

    public function test_a_permanent_failure_after_the_head_moved_records_nothing(): void
    {
        $pullRequest = $this->behind(5);
        $this->updater->failure = new PullRequestSyncFailed('refused', permanent: true);
        $this->updater->during = function (ForgePullRequest $row): void {
            $this->em->getConnection()->executeStatement(
                'UPDATE forge_pull_requests SET head_sha = :head, sync_from_sha = NULL, sync_requested_at = NULL WHERE id = :id',
                ['head' => 'moved01', 'id' => (string) $row->id],
            );
        };

        $this->handle();

        $this->em->refresh($pullRequest);
        self::assertSame('moved01', $pullRequest->headSha);
        self::assertNull($pullRequest->syncFailedReason);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_a_transient_failure_clears_the_marker_and_rethrows(): void
    {
        $pullRequest = $this->behind(5);
        $card = $this->linkedCard(5);
        $failure = new PullRequestSyncFailed('api_failed_http_status_502', permanent: false);
        $this->updater->failure = $failure;

        try {
            $this->handle();
            self::fail('Expected the transient failure to propagate.');
        } catch (PullRequestSyncFailed $e) {
            self::assertSame($failure, $e);
        }

        $this->em->refresh($pullRequest);
        self::assertNull($pullRequest->syncFromSha);
        self::assertNull($pullRequest->syncRequestedAt);
        self::assertNull($pullRequest->syncFailedReason);
        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_transient_failure_with_a_delay_asks_messenger_to_wait(): void
    {
        $pullRequest = $this->behind(5);
        $failure = new PullRequestSyncFailed('api_failed_rate_limited', permanent: false, retryAfterSeconds: 90);
        $this->updater->failure = $failure;

        try {
            $this->handle();
            self::fail('Expected the transient failure to propagate.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(90_000, $e->getRetryDelay());
            self::assertFalse($e->forceRetry());
            self::assertSame($failure, $e->getPrevious());
        }

        $this->em->refresh($pullRequest);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_a_transient_failure_keeps_a_marker_that_a_later_pass_set(): void
    {
        $pullRequest = $this->behind(5);
        $this->updater->failure = new PullRequestSyncFailed('api_failed_transport', permanent: false);
        $this->updater->during = function (ForgePullRequest $row): void {
            $this->em->getConnection()->executeStatement(
                'UPDATE forge_pull_requests SET head_sha = :head, sync_from_sha = :head WHERE id = :id',
                ['head' => 'moved01', 'id' => (string) $row->id],
            );
        };

        try {
            $this->handle();
            self::fail('Expected the transient failure to propagate.');
        } catch (PullRequestSyncFailed) {
        }

        $this->em->refresh($pullRequest);
        self::assertSame('moved01', $pullRequest->syncFromSha);
    }

    public function test_a_head_that_moved_after_the_line_was_read_is_not_marked(): void
    {
        $pullRequest = $this->behind(5);
        // The line reads the row this entity manager holds, and only the lock reads the database.
        $this->em->getConnection()->executeStatement(
            'UPDATE forge_pull_requests SET head_sha = :head WHERE id = :id',
            ['head' => 'moved01', 'id' => (string) $pullRequest->id],
        );

        $this->handle();

        self::assertSame([], $this->updater->updates);
        $this->em->refresh($pullRequest);
        self::assertNull($pullRequest->syncFromSha);
        self::assertNull($pullRequest->syncFailedReason);
    }

    public function test_a_holder_stops_the_line(): void
    {
        $this->candidate(4, PullRequestMergeability::Mergeable, approvedAt: '-1 hour');
        $behind = $this->behind(5, approvedAt: '-2 hours');

        $this->handle();

        self::assertSame([], $this->updater->updates);
        self::assertSame([], $this->queuedPasses());
        $this->em->refresh($behind);
        self::assertNull($behind->syncFromSha);
    }

    public function test_a_stale_marker_times_out_and_the_next_pull_request_syncs(): void
    {
        $stale = $this->behind(4, approvedAt: '-3 hours');
        $stale->syncFromSha = $stale->headSha;
        $stale->syncRequestedAt = $this->clock->now()->modify(\sprintf('-%d seconds', SyncLine::MARKER_LIFETIME_SECONDS));
        $next = $this->behind(5, approvedAt: '-2 hours');
        $this->em->flush();

        $this->handle();

        $this->em->refresh($stale);
        self::assertSame(SyncLine::TIMEOUT, $stale->syncFailedReason);
        self::assertNull($stale->syncFromSha);
        self::assertNull($stale->syncRequestedAt);
        self::assertCount(1, $this->updater->updates);
        self::assertSame($next, $this->updater->updates[0][0]);
    }

    public function test_a_forge_with_no_updater_fails_for_good(): void
    {
        $pullRequest = $this->behind(5, forge: 'gitlab');

        $this->handle();

        self::assertSame([], $this->updater->updates);
        $this->em->refresh($pullRequest);
        self::assertSame('no_updater', $pullRequest->syncFailedReason);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_nothing_syncs_while_the_setting_is_off(): void
    {
        $this->settings(enabled: true, syncBehind: false);
        $pullRequest = $this->behind(5);

        $this->handle();

        self::assertSame([], $this->updater->updates);
        $this->em->refresh($pullRequest);
        self::assertNull($pullRequest->syncFromSha);
    }

    public function test_nothing_syncs_while_the_automation_is_off(): void
    {
        $this->settings(enabled: false, syncBehind: true);
        $this->behind(5);

        $this->handle();

        self::assertSame([], $this->updater->updates);
    }

    public function test_nothing_syncs_for_a_project_that_saved_no_settings(): void
    {
        $this->project = $this->makeProject('sync-next-unsaved');
        $this->behind(5);

        $this->handle();

        self::assertSame([], $this->updater->updates);
    }

    public function test_nothing_syncs_while_the_board_is_off(): void
    {
        $this->disableBoard();
        $this->behind(5);

        $this->handle();

        self::assertSame([], $this->updater->updates);
    }

    private function settings(bool $enabled, bool $syncBehind): void
    {
        $repository = self::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $repository);
        $settings = $repository->findOneByProject($this->project);
        if (null === $settings) {
            $settings = new BoardAutomationSettings($this->project);
            $this->em->persist($settings);
        }
        $settings->enabled = $enabled;
        $settings->syncBehind = $syncBehind;
        $this->em->flush();
    }

    private function behind(int $number, string $approvedAt = '-1 hour', string $forge = 'github'): ForgePullRequest
    {
        return $this->candidate($number, PullRequestMergeability::Behind, $approvedAt, $forge);
    }

    private function candidate(int $number, PullRequestMergeability $mergeability, string $approvedAt = '-1 hour', string $forge = 'github'): ForgePullRequest
    {
        $row = new ForgePullRequest($this->project, $forge, 'Acme/Widgets', $number);
        $row->headSha = \sprintf('head%03d', $number);
        $row->baseBranch = 'main';
        $row->defaultBranch = 'main';
        $row->approvalId = 'review-'.$number;
        $row->approvalSha = $row->headSha;
        $row->coveredSha = $row->headSha;
        $row->approvedAt = $this->clock->now()->modify($approvedAt);
        $row->review = PullRequestReview::Approved;
        $row->mergeability = $mergeability;
        $row->checks = PullRequestChecks::Passed;
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function linkedCard(int $number, string $slug = 'in-progress'): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', ++$this->cardNumber);
        $this->em->persist($card);
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number));
        $this->em->flush();

        return $card;
    }

    private function automationOf(Card $card): ?CardAutomation
    {
        $automation = $this->service(CardAutomationRepository::class)->findOneBy(['card' => $card]);
        if (null !== $automation) {
            $this->em->refresh($automation);
        }

        return $automation;
    }

    /** @return list<CardEvent> */
    private function cardEvents(Card $card): array
    {
        return $this->service(CardEventRepository::class)->findForCard($card);
    }

    private function handle(): void
    {
        $handler = new SyncNextPullRequestHandler(
            projects: $this->service(ProjectRepository::class),
            board: $this->service(BoardAvailability::class),
            boardAutomationSettings: $this->service(BoardAutomationSettingsRepository::class),
            forgePullRequests: $this->service(ForgePullRequestRepository::class),
            updaters: new PullRequestBranchUpdaters([$this->updater]),
            em: $this->em,
            clock: $this->clock,
            logger: new NullLogger(),
            bus: $this->service(MessageBusInterface::class),
        );

        $handler(new SyncNextPullRequestCommand($this->project->id ?? throw new \LogicException('A flushed project has an id.')));
    }

    /** @return list<Envelope> */
    private function queuedPasses(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            $transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof SyncNextPullRequest,
        ));
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
