<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardAutomationAction;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\PullRequestReviewView;
use App\Module\Board\Service\PullRequestStates;
use App\Module\Board\Service\PullRequestStateView;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CardPayloadTest extends TestCase
{
    public function test_the_list_shape_carries_the_eight_summary_keys(): void
    {
        $comments = $this->createMock(CardSiteReviewCommentRepository::class);
        $comments->expects($this->never())->method('findForCards');
        $links = $this->createMock(CardLinkRepository::class);
        $links->expects($this->never())->method('findForCards');
        $cards = $this->createMock(CardRepository::class);
        $cards->expects($this->never())->method('findChildrenOfCards');
        $states = $this->createMock(CardPullRequestStates::class);
        $states->expects($this->never())->method('forCards');
        $pauses = $this->createMock(CardPauseRepository::class);
        $pauses->expects($this->never())->method('findActiveForCardIds');

        $rows = new CardPayload($comments, $links, $cards, $states, $pauses)->forCardList([$this->card()]);

        self::assertCount(1, $rows);
        self::assertSame(
            ['cardId', 'number', 'title', 'type', 'status', 'reporter', 'parentCardId', 'updatedAt'],
            array_keys($rows[0]),
        );
        self::assertSame('Drag ordering', $rows[0]['title']);
    }

    public function test_the_full_shape_does_query_the_site_review_comments_and_the_card_links(): void
    {
        $comments = $this->createMock(CardSiteReviewCommentRepository::class);
        // Without these the test above passes even on a payload that never ran
        // the queries on either path.
        $comments->expects($this->once())->method('findForCards')->willReturn([]);
        $links = $this->createMock(CardLinkRepository::class);
        $links->expects($this->once())->method('findForCards')->willReturn([]);
        $feature = $this->card();
        $epic = $this->card(CardType::Epic);
        $otherEpic = $this->card(CardType::Epic);
        $cards = $this->createMock(CardRepository::class);
        // One call for the whole page, with the epics alone.
        $cards->expects($this->once())->method('findChildrenOfCards')->with([$epic, $otherEpic])->willReturn([]);
        $pauses = $this->createMock(CardPauseRepository::class);
        $pauses->expects($this->once())->method('findActiveForCardIds')->willReturn([]);

        $rows = new CardPayload($comments, $links, $cards, $this->states(), $pauses)->forCards([$feature, $epic, $otherEpic]);

        self::assertArrayHasKey('body', $rows[0]);
        self::assertSame([], $rows[0]['siteReviewComments']);
        self::assertSame([], $rows[0]['relatedCards']);
        self::assertNull($rows[0]['progress']);
        self::assertSame(['done' => 0, 'total' => 0], $rows[1]['progress']);
    }

    public function test_a_page_with_no_epic_reads_no_children(): void
    {
        $cards = $this->createMock(CardRepository::class);
        $cards->expects($this->never())->method('findChildrenOfCards');

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $cards, $this->states(), $this->createStub(CardPauseRepository::class))->forCards([$this->card()]);

        self::assertSame([], $rows[0]['children']);
    }

    public function test_the_full_shape_renders_the_stored_pull_request_state_and_the_automation(): void
    {
        $card = $this->card();
        $this->setId($card, Uuid::v7());
        $tracked = new CardPullRequest($card, 'https://github.com/ubermuda/loupe/pull/7', Forge::GitHub, 'ubermuda/loupe', 7);
        $this->setId($tracked, Uuid::v7());
        $untracked = new CardPullRequest($card, 'https://example.com/pr/1');
        $this->setId($untracked, Uuid::v7());
        $card->pullRequests->add($tracked);
        $card->pullRequests->add($untracked);
        $automation = new CardAutomation($card);
        $automation->lastAction = CardAutomationAction::FixRequested;
        $automation->lastActionAt = new \DateTimeImmutable('2026-09-27T10:00:00+00:00');
        $states = $this->createMock(CardPullRequestStates::class);
        // One read for the whole page, never one per card.
        $states->expects($this->once())->method('forCards')->with([$card])->willReturn(new PullRequestStates(
            [(string) $tracked->id => new PullRequestStateView(
                PullRequestState::Open,
                true,
                PullRequestChecks::Failed,
                ['phpunit'],
                PullRequestMergeability::Conflicting,
                PullRequestReviewView::ChangesRequested,
                false,
                new \DateTimeImmutable('2026-09-27T11:00:00+00:00'),
            )],
            [(string) $card->id => $automation],
        ));

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $states, $this->createStub(CardPauseRepository::class))->forCards([$card]);

        self::assertSame([
            'state' => 'open',
            'draft' => true,
            'checks' => 'failed',
            'failedChecks' => ['phpunit'],
            'mergeability' => 'conflicting',
            'review' => 'changes-requested',
            'readyToMerge' => false,
            'refreshedAt' => '2026-09-27T11:00:00+00:00',
        ], $rows[0]['pullRequests'][0]['state']);
        self::assertNull($rows[0]['pullRequests'][1]['state']);
        self::assertSame([
            'lastAction' => 'fix-requested',
            'lastActionAt' => '2026-09-27T10:00:00+00:00',
        ], $rows[0]['automation']);
    }

    public function test_a_card_with_no_automation_row_reads_a_null_automation(): void
    {
        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $this->states(), $this->createStub(CardPauseRepository::class))->forCards([$this->card()]);

        self::assertArrayHasKey('automation', $rows[0]);
        self::assertNull($rows[0]['automation']);
    }

    public function test_a_pull_request_that_loupe_never_read_reads_a_null_state(): void
    {
        $card = $this->card();
        $link = new CardPullRequest($card, 'https://github.com/ubermuda/loupe/pull/8', Forge::GitHub, 'ubermuda/loupe', 8);
        $this->setId($link, Uuid::v7());
        $card->pullRequests->add($link);
        $states = $this->createStub(CardPullRequestStates::class);
        $states->method('forCards')->willReturn(new PullRequestStates([(string) $link->id => new PullRequestStateView(
            PullRequestState::Open,
            false,
            PullRequestChecks::Pending,
            [],
            PullRequestMergeability::Unknown,
            PullRequestReviewView::None,
            false,
            null,
        )]));

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $states, $this->createStub(CardPauseRepository::class))->forCards([$card]);

        self::assertNull($rows[0]['pullRequests'][0]['state']);
    }

    public function test_the_full_shape_renders_the_active_pause_of_each_card(): void
    {
        $paused = $this->card();
        $this->setId($paused, Uuid::v7());
        $free = $this->card();
        $this->setId($free, Uuid::v7());
        $pause = new CardPause($paused, $paused->project, 'review-failed', 'fix-on-review', CardPauseKind::Retries, new \DateTimeImmutable('2026-10-02T10:00:00+00:00'));
        $this->setId($pause, Uuid::v7());
        $pauses = $this->createMock(CardPauseRepository::class);
        // One read for the whole page, never one per card.
        $pauses->expects($this->once())->method('findActiveForCardIds')->with([$paused->id, $free->id])->willReturn([(string) $paused->id => $pause]);

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $this->states(), $pauses)->forCards([$paused, $free]);

        self::assertSame([
            'pauseId' => (string) $pause->id,
            'kind' => 'retries',
            'reason' => 'review-failed',
            'ruleId' => 'fix-on-review',
            'since' => '2026-10-02T10:00:00+00:00',
        ], $rows[0]['pause']);
        self::assertArrayHasKey('pause', $rows[1]);
        self::assertNull($rows[1]['pause']);
    }

    private function states(): CardPullRequestStates
    {
        $states = $this->createStub(CardPullRequestStates::class);
        $states->method('forCards')->willReturn(new PullRequestStates());

        return $states;
    }

    private function setId(object $entity, Uuid $id): void
    {
        new \ReflectionProperty($entity, 'id')->setValue($entity, $id);
    }

    private function card(CardType $type = CardType::Feature): Card
    {
        $owner = new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed');

        $project = new Project($owner, 'board');

        return new Card(
            project: $project,
            column: new BoardColumn(project: $project, label: 'board.card.status.backlog', slug: 'backlog', position: 0, backlog: true),
            title: 'Drag ordering',
            body: 'Body',
            number: 7,
            type: $type,
            origin: CardReporter::Agent,
        );
    }
}
