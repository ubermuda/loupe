<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\VerdictReviewSettler;
use App\Module\Forge\Command\ReadPullRequestStateHandler;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ApprovalCoverageReaders;
use App\Module\Forge\Service\PullRequestReviewFailed;
use App\Module\Forge\Service\PullRequestReviewKind;
use App\Module\Forge\Service\PullRequestReviewPosters;
use App\Module\Forge\Service\PullRequestStateReaders;
use App\Module\Forge\Service\PullRequestUnreadable;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Fake\FakeAuthorReader;
use App\Tests\Module\Board\Fake\FakeReviewerForgeAccount;
use App\Tests\Module\Board\Fake\FakeReviewPoster;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class VerdictReviewSettlerTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private FakeReviewPoster $poster;
    private FakeReviewerForgeAccount $account;
    private FakeAuthorReader $authors;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('verdict-settle');
        $this->poster = new FakeReviewPoster();
        $this->account = new FakeReviewerForgeAccount();
        $this->authors = new FakeAuthorReader();
        $this->em->persist(new BoardAutomationSettings($this->project, postWidgetReviews: true));
        $this->em->flush();
    }

    public function test_a_review_is_posted_as_the_reviewer_and_the_delivery_records_its_url(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::RequestChanges, 'Fix the footer');

        self::assertNull($this->settle($card));

        self::assertCount(1, $this->poster->posts);
        self::assertSame(PullRequestReviewKind::RequestChanges, $this->poster->posts[0]['kind']);
        self::assertSame((string) $this->project->owner->id, $this->poster->posts[0]['userId']);
        self::assertSame(CardVerdictDeliveryState::Posted, $delivery->state);
        self::assertSame('https://github.com/acme/widgets/pull/7#pullrequestreview-1', $delivery->reviewUrl);
        self::assertNotNull($delivery->settledAt);
        self::assertNull($delivery->reason);
    }

    public function test_a_review_that_the_forge_gives_no_url_for_is_still_posted(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '');
        $this->poster->withoutUrl = true;

        $this->settle($card);

        self::assertSame(CardVerdictDeliveryState::Posted, $delivery->state);
        self::assertNull($delivery->reviewUrl);
    }

    public function test_the_body_is_the_message_then_each_copied_note_with_its_marker(): void
    {
        $card = $this->card($this->project);
        $notes = [
            ['id' => 'note-1', 'url' => 'https://app.example/a', 'body' => 'Footer overlaps', 'anchorCount' => 1],
            ['id' => 'note-2', 'url' => 'https://app.example/b', 'body' => 'Logo is blurry', 'anchorCount' => 0],
        ];
        $this->delivery($card, 7, CardVerdictKind::RequestChanges, 'Two problems', $notes);

        $this->settle($card);

        self::assertSame(
            $this->sourceLine($card, ' · [Open the preview](https://app.example/a)')."\n\nTwo problems\n\nhttps://app.example/a\n\nFooter overlaps\n<!-- loupe-note:note-1 -->\n\nhttps://app.example/b\n\nLogo is blurry\n<!-- loupe-note:note-2 -->",
            $this->poster->posts[0]['body'],
        );
    }

    public function test_a_note_with_an_unreadable_address_adds_no_preview_link(): void
    {
        $card = $this->card($this->project);
        $notes = [['id' => 'note-1', 'url' => 'not a url', 'body' => 'Footer overlaps', 'anchorCount' => 0]];
        $this->delivery($card, 7, CardVerdictKind::RequestChanges, '', $notes);

        $this->settle($card);

        self::assertSame(
            $this->sourceLine($card)."\n\nnot a url\n\nFooter overlaps\n<!-- loupe-note:note-1 -->",
            $this->poster->posts[0]['body'],
        );
    }

    public function test_an_approval_with_no_message_and_no_note_names_the_site_review(): void
    {
        $card = $this->card($this->project);
        $this->delivery($card, 7, CardVerdictKind::Approve, '');

        $this->settle($card);

        self::assertSame([['kind' => PullRequestReviewKind::Approve, 'body' => $this->sourceLine($card)]], $this->postedKindsAndBodies());
    }

    public function test_a_review_on_the_reviewers_own_pull_request_is_a_comment(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '', authorId: '4242');

        $this->settle($card);

        self::assertSame(CardVerdictDeliveryState::Commented, $delivery->state);
        self::assertSame(PullRequestReviewKind::Comment, $this->poster->posts[0]['kind']);
        self::assertSame($this->sourceLine($card), $this->poster->posts[0]['body']);
    }

    public function test_an_own_pull_request_comment_keeps_the_message(): void
    {
        $card = $this->card($this->project);
        $this->delivery($card, 7, CardVerdictKind::RequestChanges, 'Needs work', authorId: '4242');

        $this->settle($card);

        self::assertSame(PullRequestReviewKind::Comment, $this->poster->posts[0]['kind']);
        self::assertSame($this->sourceLine($card)."\n\nNeeds work", $this->poster->posts[0]['body']);
    }

    public function test_an_unread_author_is_read_before_the_review_is_posted(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '', authorRead: false);
        $this->authors->snapshot = new PullRequestSnapshot(authorId: '4242', authorLogin: 'reviewer');

        self::assertNull($this->settle($card));

        self::assertSame(1, $this->authors->reads);
        self::assertSame(PullRequestReviewKind::Comment, $this->poster->posts[0]['kind']);
        self::assertSame(CardVerdictDeliveryState::Commented, $delivery->state);
    }

    public function test_a_deleted_author_is_read_once_and_does_not_block_the_review(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '', authorRead: false);

        self::assertNull($this->settle($card));

        self::assertSame(1, $this->authors->reads);
        self::assertSame(PullRequestReviewKind::Approve, $this->poster->posts[0]['kind']);
        self::assertSame(CardVerdictDeliveryState::Posted, $delivery->state);
    }

    public function test_an_author_that_cannot_be_read_leaves_the_delivery_pending(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '', authorRead: false);
        $this->authors->failure = new PullRequestUnreadable('rate_limited', transient: true);

        self::assertSame(VerdictReviewSettler::AUTHOR_UNREAD, $this->settle($card));

        self::assertSame([], $this->poster->posts);
        self::assertSame(CardVerdictDeliveryState::Pending, $delivery->state);
    }

    public function test_a_pull_request_that_the_author_read_finds_closed_is_skipped(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '', authorRead: false);
        $this->authors->snapshot = new PullRequestSnapshot(state: PullRequestState::Merged, authorId: '1', authorLogin: 'someone');

        self::assertNull($this->settle($card));

        self::assertSame([], $this->poster->posts);
        self::assertSame(CardVerdictDeliveryState::Skipped, $delivery->state);
        self::assertSame('not-open', $delivery->reason);
    }

    public function test_a_closed_pull_request_is_skipped_without_reading_its_author(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '', authorRead: false);
        $delivery->pullRequest->state = PullRequestState::Closed;
        $this->authors->failure = new PullRequestUnreadable('rate_limited', transient: true);

        self::assertNull($this->settle($card));

        self::assertSame(0, $this->authors->reads);
        self::assertSame(CardVerdictDeliveryState::Skipped, $delivery->state);
    }

    public function test_a_read_author_is_not_read_again(): void
    {
        $card = $this->card($this->project);
        $this->delivery($card, 7, CardVerdictKind::Approve, '');

        $this->settle($card);

        self::assertSame(0, $this->authors->reads);
    }

    public function test_an_opt_in_that_is_off_skips_every_delivery_and_posts_nothing(): void
    {
        $card = $this->card($this->project);
        $first = $this->delivery($card, 7, CardVerdictKind::Approve, '');
        $second = $this->delivery($card, 8, CardVerdictKind::Approve, '');
        $this->optIn(false);

        self::assertNull($this->settle($card));

        self::assertSame([], $this->poster->posts);
        foreach ([$first, $second] as $delivery) {
            self::assertSame(CardVerdictDeliveryState::Skipped, $delivery->state);
            self::assertNotNull($delivery->settledAt);
        }
        self::assertSame([], $this->pendingOf($card));
    }

    public function test_a_pull_request_that_is_no_longer_open_is_skipped(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '');
        $delivery->pullRequest->state = PullRequestState::Merged;

        $this->settle($card);

        self::assertSame([], $this->poster->posts);
        self::assertSame(CardVerdictDeliveryState::Skipped, $delivery->state);
        self::assertSame('not-open', $delivery->reason);
    }

    public function test_a_reviewer_with_no_working_connection_is_refused(): void
    {
        foreach (['none', 'expired'] as $i => $state) {
            $card = $this->card($this->project, number: $i + 10);
            $delivery = $this->delivery($card, $i + 20, CardVerdictKind::Approve, '');
            $this->account->state = $state;

            self::assertNull($this->settle($card));

            self::assertSame(CardVerdictDeliveryState::Refused, $delivery->state, $state);
            self::assertSame(CardVerdictDelivery::REASON_CONNECTION_EXPIRED, $delivery->reason);
        }
        self::assertSame([], $this->poster->posts);
    }

    public function test_a_verdict_with_no_reviewer_is_refused(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '', reviewer: false);

        $this->settle($card);

        self::assertSame(CardVerdictDeliveryState::Refused, $delivery->state);
        self::assertSame(CardVerdictDelivery::REASON_CONNECTION_EXPIRED, $delivery->reason);
    }

    public function test_a_connection_failure_of_the_forge_is_refused_as_an_expired_connection(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '');
        $this->poster->failure = new PullRequestReviewFailed('connection_expired', true);

        self::assertNull($this->settle($card));

        self::assertSame(CardVerdictDeliveryState::Refused, $delivery->state);
        self::assertSame(CardVerdictDelivery::REASON_CONNECTION_EXPIRED, $delivery->reason);
    }

    public function test_a_permanent_failure_is_refused_with_its_cause(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->delivery($card, 7, CardVerdictKind::Approve, '');
        $this->poster->failure = new PullRequestReviewFailed('permission', true);

        self::assertNull($this->settle($card));

        self::assertSame(CardVerdictDeliveryState::Refused, $delivery->state);
        self::assertSame('permission', $delivery->reason);
    }

    public function test_a_transient_failure_leaves_the_delivery_pending_and_settles_the_others(): void
    {
        $card = $this->card($this->project);
        $failing = $this->delivery($card, 7, CardVerdictKind::Approve, '');
        $this->poster->failure = new PullRequestReviewFailed('rate_limited', false);
        $other = $this->delivery($card, 8, CardVerdictKind::Approve, '');
        $other->pullRequest->state = PullRequestState::Closed;

        self::assertSame('rate_limited', $this->settle($card));

        self::assertSame(CardVerdictDeliveryState::Pending, $failing->state);
        self::assertNull($failing->settledAt);
        self::assertSame(CardVerdictDeliveryState::Skipped, $other->state);
        self::assertSame([(string) $failing->id], $this->pendingOf($card));
    }

    public function test_the_result_says_whether_a_delivery_settled(): void
    {
        $card = $this->card($this->project);
        $this->delivery($card, 7, CardVerdictKind::Approve, '');
        $this->em->flush();

        self::assertTrue($this->settler()->settle($card)->changed);
        self::assertFalse($this->settler()->settle($card)->changed);
    }

    public function test_a_transient_failure_alone_changes_nothing(): void
    {
        $card = $this->card($this->project);
        $this->delivery($card, 7, CardVerdictKind::Approve, '');
        $this->poster->failure = new PullRequestReviewFailed('rate_limited', false);
        $this->em->flush();

        self::assertFalse($this->settler()->settle($card)->changed);
    }

    public function test_a_settled_delivery_is_not_posted_again(): void
    {
        $card = $this->card($this->project);
        $this->delivery($card, 7, CardVerdictKind::Approve, '');

        $this->settle($card);
        $this->settle($card);

        self::assertCount(1, $this->poster->posts);
    }

    /** @return list<array{kind: PullRequestReviewKind, body: string}> */
    private function postedKindsAndBodies(): array
    {
        return array_map(static fn (array $post): array => ['kind' => $post['kind'], 'body' => $post['body']], $this->poster->posts);
    }

    /** @return list<string> */
    private function pendingOf(Card $card): array
    {
        $repository = self::getContainer()->get(CardVerdictDeliveryRepository::class);
        self::assertInstanceOf(CardVerdictDeliveryRepository::class, $repository);

        return $repository->findPendingIdsForCard($card);
    }

    private function optIn(bool $on): void
    {
        $settings = $this->service(BoardAutomation::class)->settingsOf($this->project);
        $settings->postWidgetReviews = $on;
        $this->em->flush();
    }

    private function settle(Card $card): ?string
    {
        $this->em->flush();

        return $this->settler()->settle($card)->failure;
    }

    private function readHandler(): ReadPullRequestStateHandler
    {
        return new ReadPullRequestStateHandler(
            $this->service(ForgePullRequestRepository::class),
            new PullRequestStateReaders([$this->authors]),
            new ApprovalCoverageReaders([]),
            $this->em,
            $this->service(MessageBusInterface::class),
            new EventDispatcher(),
            new MockClock('2026-10-08 12:00:00'),
            new NullLogger(),
        );
    }

    private function sourceLine(Card $card, string $suffix = ''): string
    {
        $url = $this->service(UrlGeneratorInterface::class)->generate('app_board_card', [
            'projectId' => (string) $card->project->id,
            'cardId' => (string) $card->id,
            'tab' => 'feedback',
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return \sprintf('Sent from the Loupe site review of card %d. [Open the notes in Loupe](%s)%s', $card->number, $url, $suffix);
    }

    private function settler(): VerdictReviewSettler
    {
        $translator = $this->service(TranslatorInterface::class);

        return new VerdictReviewSettler(
            $this->service(CardVerdictDeliveryRepository::class),
            $this->service(BoardAutomation::class),
            new PullRequestReviewPosters([$this->poster]),
            $this->account,
            $this->readHandler(),
            $translator,
            $this->em,
            new MockClock('2026-10-08 12:00:00'),
            $this->service(UrlGeneratorInterface::class),
            'en',
        );
    }

    /**
     * @param list<array{id: string, url: string, body: string, anchorCount: int}> $notes
     */
    private function delivery(Card $card, int $number, CardVerdictKind $kind, string $message, array $notes = [], ?string $authorId = null, bool $reviewer = true, bool $authorRead = true): CardVerdictDelivery
    {
        $pullRequest = $this->linkedPullRequest($card, $number, authorId: $authorId, authorRead: $authorRead);
        $verdict = new CardVerdict($card, $kind, $reviewer ? $this->project->owner : null, $message, $notes);
        $this->em->persist($verdict);
        $delivery = new CardVerdictDelivery($verdict, $pullRequest);
        $this->em->persist($delivery);

        return $delivery;
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
