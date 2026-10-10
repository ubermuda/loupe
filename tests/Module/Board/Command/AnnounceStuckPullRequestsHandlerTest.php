<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\AnnounceStuckPullRequestsCommand;
use App\Module\Board\Command\AnnounceStuckPullRequestsHandler;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\StuckPullRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Service\ForgePullRequestWrites;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AnnounceStuckPullRequestsHandlerTest extends KernelTestCase
{
    use CardStateFixtures;

    /** @var list<CardChanged> */
    private array $changes = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->changes = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, function (CardChanged $event): void {
            $this->changes[] = $event;
        });
    }

    public function test_a_ready_pull_request_past_the_delay_refreshes_its_card_once(): void
    {
        $card = $this->stateCard($this->stateProject('announce-once'));
        $row = $this->ready($card, '-20 minutes');

        self::assertSame(1, $this->announce());
        self::assertSame([(string) $card->id], $this->changedCardIds());
        self::assertSame($row->readySince?->format('Y-m-d H:i:s'), $this->reload($row)->stuckAnnouncedFor?->format('Y-m-d H:i:s'));

        $this->changes = [];
        self::assertSame(0, $this->announce());
        self::assertSame([], $this->changes);
    }

    public function test_a_ready_pull_request_inside_the_delay_is_left_alone(): void
    {
        $card = $this->stateCard($this->stateProject('announce-fresh'));
        $row = $this->ready($card, '-5 minutes');

        self::assertSame(0, $this->announce());
        self::assertNull($this->reload($row)->stuckAnnouncedFor);
    }

    public function test_the_delay_of_the_board_decides(): void
    {
        $project = $this->stateProject('announce-delay');
        $this->em()->persist(new BoardAutomationSettings($project, stuckDelayMinutes: 60));
        $this->em()->flush();
        $card = $this->stateCard($project);
        $this->ready($card, '-20 minutes');

        self::assertSame(0, $this->announce());
    }

    public function test_a_pull_request_that_turned_ready_again_is_announced_again(): void
    {
        $card = $this->stateCard($this->stateProject('announce-again'));
        $row = $this->ready($card, '-40 minutes');
        $this->announce();

        $row = $this->reload($row);
        $row->readySince = new \DateTimeImmutable('-20 minutes');
        $this->em()->flush();
        $this->changes = [];

        self::assertSame(1, $this->announce());
    }

    public function test_a_merge_in_flight_defers_the_announcement_until_its_marker_expires(): void
    {
        $card = $this->stateCard($this->stateProject('announce-merge-in-flight'));
        $row = $this->ready($card, '-20 minutes');
        $row->mergeRequestedSha = 'head1';
        $row->mergeRequestedAt = new \DateTimeImmutable('-5 minutes');
        $this->em()->flush();

        self::assertSame(0, $this->announce());
        self::assertNull($this->reload($row)->stuckAnnouncedFor);

        $row = $this->reload($row);
        $row->mergeRequestedAt = new \DateTimeImmutable(\sprintf('-%d seconds', ForgePullRequestWrites::MARKER_LIFETIME_SECONDS + 60));
        $this->em()->flush();

        self::assertSame(1, $this->announce());
        self::assertSame([(string) $card->id], $this->changedCardIds());
    }

    public function test_the_announcement_is_not_recorded_when_a_merge_started_after_the_read(): void
    {
        $card = $this->stateCard($this->stateProject('announce-merge-race'));
        $row = $this->ready($card, '-20 minutes');
        $row->mergeRequestedSha = 'head1';
        $row->mergeRequestedAt = new \DateTimeImmutable('-1 minute');
        $this->em()->flush();

        $repository = self::getContainer()->get(StuckPullRequestRepository::class);
        self::assertInstanceOf(StuckPullRequestRepository::class, $repository);
        $repository->markAnnounced((string) $row->id, $this->reload($row)->readySince?->format('Y-m-d H:i:s') ?? '', new \DateTimeImmutable());

        self::assertNull($this->reload($row)->stuckAnnouncedFor);
    }

    public function test_a_pull_request_that_is_not_ready_is_left_alone(): void
    {
        $card = $this->stateCard($this->stateProject('announce-not-ready'));
        $this->linkPullRequest($card, ['checks' => PullRequestChecks::Failed, 'readyToMerge' => false], new \DateTimeImmutable('-1 hour'));

        self::assertSame(0, $this->announce());
    }

    public function test_a_longer_delay_redraws_the_ready_cards_and_lets_the_sweep_announce_again(): void
    {
        $project = $this->stateProject('announce-longer');
        $card = $this->stateCard($project);
        $row = $this->ready($card, '-20 minutes');
        $this->announce();
        $this->changes = [];

        $save = self::getContainer()->get(SaveBoardAutomationSettingsHandler::class);
        self::assertInstanceOf(SaveBoardAutomationSettingsHandler::class, $save);
        $save(new SaveBoardAutomationSettingsCommand(
            project: $project,
            enabled: true,
            commentOnFixQueued: false,
            commentOnStaleApproval: false,
            syncBehind: false,
            mergePullRequests: false,
            changeBase: false,
            postWidgetReviews: false,
            siteReviewCheck: false,
            stuckDelayMinutes: 60,
        ));

        self::assertSame([(string) $card->id], $this->changedCardIds());
        self::assertNull($this->reload($row)->stuckAnnouncedFor);
    }

    public function test_the_announcement_is_not_recorded_when_the_delay_grew_after_the_read(): void
    {
        $project = $this->stateProject('announce-race');
        $settings = new BoardAutomationSettings($project, stuckDelayMinutes: 60);
        $this->em()->persist($settings);
        $this->em()->flush();
        $card = $this->stateCard($project);
        $row = $this->ready($card, '-20 minutes');

        $repository = self::getContainer()->get(StuckPullRequestRepository::class);
        self::assertInstanceOf(StuckPullRequestRepository::class, $repository);
        $repository->markAnnounced((string) $row->id, $this->reload($row)->readySince?->format('Y-m-d H:i:s') ?? '', new \DateTimeImmutable());

        self::assertNull($this->reload($row)->stuckAnnouncedFor);
    }

    private function ready(Card $card, string $since): ForgePullRequest
    {
        return $this->linkPullRequest($card, [
            'checks' => PullRequestChecks::Passed,
            'mergeability' => PullRequestMergeability::Mergeable,
            'review' => PullRequestReview::Approved,
            'approvalId' => 'review1',
            'approvalSha' => 'head1',
            'coveredSha' => 'head1',
            'readyToMerge' => true,
        ], new \DateTimeImmutable($since));
    }

    private function announce(): int
    {
        $handler = self::getContainer()->get(AnnounceStuckPullRequestsHandler::class);
        self::assertInstanceOf(AnnounceStuckPullRequestsHandler::class, $handler);

        return $handler(new AnnounceStuckPullRequestsCommand());
    }

    /** @return list<string> */
    private function changedCardIds(): array
    {
        return array_map(static fn (CardChanged $event): string => (string) $event->cardId, $this->changes);
    }

    private function reload(ForgePullRequest $row): ForgePullRequest
    {
        $this->em()->clear();

        return $this->em()->find(ForgePullRequest::class, $row->id) ?? throw new \LogicException('The pull request is stored.');
    }
}
