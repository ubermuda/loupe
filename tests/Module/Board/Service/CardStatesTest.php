<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\CardState;
use App\Module\Board\Service\CardStateCode;
use App\Module\Board\Service\CardStateKind;
use App\Module\Board\Service\CardStates;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardStatesTest extends KernelTestCase
{
    use CardStateFixtures;

    public function test_a_card_with_no_fact_has_no_state(): void
    {
        $card = $this->stateCard($this->stateProject('state-none'));

        self::assertNull($this->stateOf($card));
    }

    public function test_a_card_in_a_terminal_column_has_no_state_whatever_it_holds(): void
    {
        $card = $this->stateCard($this->stateProject('state-terminal'), 'done');
        $this->pauseCard($card);
        $this->requestWork($card);

        self::assertNull($this->stateOf($card));
    }

    public function test_a_pause_makes_the_card_stuck_since_the_pause_began(): void
    {
        $card = $this->stateCard($this->stateProject('state-pause'));
        $this->pauseCard($card, '2026-10-02 09:00:00');

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::Stuck, $state?->kind);
        self::assertSame(CardStateCode::Paused, $state->reason->code);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00:00'), $state->reason->since);
        self::assertSame(['%reason%' => 'review-failed'], $state->reason->params);
        self::assertSame('board.card_state.reason.paused', $state->reason->translationKey());
    }

    public function test_a_run_that_gave_up_makes_the_card_stuck_since_the_run_ended(): void
    {
        $card = $this->stateCard($this->stateProject('state-run-stopped'));
        $ended = new \DateTimeImmutable('2026-10-02 09:50:00');

        $state = $this->stateOf($card, [(string) $card->id => new CardRunWarning('run-1', WorkerRunState::GaveUp, 'Tests fail.', $ended)]);

        self::assertSame(CardStateKind::Stuck, $state?->kind);
        self::assertSame(CardStateCode::RunStopped, $state->reason->code);
        self::assertEquals($ended, $state->reason->since);
        self::assertSame(['%state%' => 'gave-up'], $state->reason->params);
    }

    public function test_failed_checks_make_the_card_stuck_since_they_began_to_fail(): void
    {
        $card = $this->stateCard($this->stateProject('state-checks'));
        $this->linkPullRequest($card, ['checks' => PullRequestChecks::Failed], new \DateTimeImmutable('2026-10-02 08:00:00'));

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::Stuck, $state?->kind);
        self::assertSame(CardStateCode::ChecksFailed, $state->reason->code);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 08:00:00'), $state->reason->since);
    }

    public function test_a_conflict_makes_the_card_stuck(): void
    {
        $card = $this->stateCard($this->stateProject('state-conflict'));
        $this->linkPullRequest($card, ['mergeability' => PullRequestMergeability::Conflicting]);

        self::assertSame(CardStateCode::Conflicting, $this->stateOf($card)?->reason->code);
    }

    public function test_failed_checks_with_a_fix_in_flight_make_the_card_work_not_stuck(): void
    {
        $card = $this->stateCard($this->stateProject('state-fix-live'));
        $this->linkPullRequest($card, ['checks' => PullRequestChecks::Failed, 'mergeability' => PullRequestMergeability::Conflicting]);
        $this->requestWork($card, 'fix');

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::Working, $state?->kind);
        self::assertSame([], $state->others);
    }

    public function test_an_open_fix_run_also_counts_as_fix_work(): void
    {
        $card = $this->stateCard($this->stateProject('state-fix-run'));
        $this->linkPullRequest($card, ['checks' => PullRequestChecks::Failed]);
        $this->openRun($card, 'fix');

        self::assertSame(CardStateKind::Working, $this->stateOf($card)?->kind);
    }

    public function test_other_work_does_not_hide_failed_checks(): void
    {
        $card = $this->stateCard($this->stateProject('state-other-work'));
        $this->linkPullRequest($card, ['checks' => PullRequestChecks::Failed]);
        $this->requestWork($card, 'implement');

        $state = $this->stateOf($card);

        self::assertSame(CardStateCode::ChecksFailed, $state?->reason->code);
        self::assertSame([CardStateCode::WorkRequested], array_map(static fn ($reason) => $reason->code, $state->others));
    }

    public function test_a_closed_pull_request_with_failed_checks_is_no_problem(): void
    {
        $card = $this->stateCard($this->stateProject('state-closed-pr'));
        $this->linkPullRequest($card, ['state' => \App\Module\Forge\Entity\PullRequestState::Merged, 'checks' => PullRequestChecks::Failed]);

        self::assertNull($this->stateOf($card));
    }

    public function test_a_ready_pull_request_that_nothing_merges_turns_stuck_after_the_delay(): void
    {
        $card = $this->stateCard($this->stateProject('state-ready'));
        $this->approvedPullRequest($card, new \DateTimeImmutable('-1 hour'));

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::Stuck, $state?->kind);
        self::assertSame(CardStateCode::ReadyNotMerged, $state->reason->code);
        self::assertEquals(
            new \DateTimeImmutable('-1 hour')->modify(\sprintf('+%d minutes', CardStates::STUCK_DELAY_MINUTES))->format('Y-m-d H:i'),
            $state->reason->since?->format('Y-m-d H:i'),
        );
    }

    public function test_a_ready_pull_request_inside_the_delay_is_not_stuck(): void
    {
        $card = $this->stateCard($this->stateProject('state-ready-fresh'));
        $this->approvedPullRequest($card, new \DateTimeImmutable('-5 minutes'));

        self::assertNull($this->stateOf($card));
    }

    public function test_a_ready_pull_request_with_a_merge_in_flight_works_instead(): void
    {
        $card = $this->stateCard($this->stateProject('state-ready-merging'));
        $row = $this->approvedPullRequest($card, new \DateTimeImmutable('-1 hour'));
        $row->mergeRequestedSha = 'head1';
        $row->mergeRequestedAt = new \DateTimeImmutable('-2 minutes');
        $this->em()->flush();

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::Working, $state?->kind);
        self::assertSame(CardStateCode::ForgeRequestPending, $state->reason->code);
    }

    public function test_a_ready_pull_request_with_a_merge_work_request_live_is_not_stuck(): void
    {
        $card = $this->stateCard($this->stateProject('state-ready-merge-work'));
        $this->approvedPullRequest($card, new \DateTimeImmutable('-1 hour'));
        $this->requestWork($card, 'merge');

        self::assertSame(CardStateKind::Working, $this->stateOf($card)?->kind);
    }

    public function test_a_ready_pull_request_that_waits_for_an_approval_needs_you_and_never_turns_stuck(): void
    {
        $card = $this->stateCard($this->stateProject('state-ready-unapproved'));
        $this->linkPullRequest($card, ['checks' => PullRequestChecks::Passed, 'mergeability' => PullRequestMergeability::Mergeable, 'readyToMerge' => true], new \DateTimeImmutable('-1 hour'));

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::NeedsYou, $state?->kind);
        self::assertSame(CardStateCode::WaitsForApproval, $state->reason->code);
        self::assertSame([], $state->others);
    }

    public function test_a_pull_request_on_another_base_does_not_wait_for_an_approval(): void
    {
        $card = $this->stateCard($this->stateProject('state-epic-base'));
        $this->linkPullRequest($card, ['checks' => PullRequestChecks::Passed, 'baseBranch' => 'epic/1']);

        self::assertNull($this->stateOf($card));
    }

    public function test_a_stage_document_in_review_needs_you_since_its_version_time(): void
    {
        $card = $this->stateCard($this->stateProject('state-document'), 'tech-design');
        $this->reviewDocument($card, 'tech-design', '2026-10-02 09:20:00');

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::NeedsYou, $state?->kind);
        self::assertSame(CardStateCode::DocumentInReview, $state->reason->code);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:20:00'), $state->reason->since);
    }

    public function test_a_document_in_review_that_the_slot_does_not_read_is_no_stage_document(): void
    {
        $card = $this->stateCard($this->stateProject('state-other-document'), 'tech-design');
        $this->reviewDocument($card, 'meeting-notes');

        self::assertNull($this->stateOf($card));
    }

    public function test_an_open_question_needs_you_since_it_was_asked(): void
    {
        $card = $this->stateCard($this->stateProject('state-question'));
        $this->askOwner($card, InboxItemKind::Question, '2026-10-02 09:15:00');

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::NeedsYou, $state?->kind);
        self::assertSame(CardStateCode::OpenQuestion, $state->reason->code);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:15:00'), $state->reason->since);
    }

    public function test_a_wait_item_that_loupe_opened_is_no_question(): void
    {
        $card = $this->stateCard($this->stateProject('state-wait-item'));
        $this->askOwner($card, InboxItemKind::Wait);

        self::assertNull($this->stateOf($card));
    }

    public function test_a_work_request_makes_the_card_work_since_the_request_was_made(): void
    {
        $card = $this->stateCard($this->stateProject('state-request'));
        $this->requestWork($card, 'implement', '2026-10-02 09:30:00');

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::Working, $state?->kind);
        self::assertSame(CardStateCode::WorkRequested, $state->reason->code);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:30:00'), $state->reason->since);
    }

    public function test_an_open_run_makes_the_card_work_since_the_run_started(): void
    {
        $card = $this->stateCard($this->stateProject('state-run'));
        $this->openRun($card, 'implement', '2026-10-02 09:45:00');

        $state = $this->stateOf($card);

        self::assertSame(CardStateCode::RunOpen, $state?->reason->code);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:45:00'), $state->reason->since);
    }

    public function test_a_card_that_an_open_blocker_holds_waits_since_the_hold_began(): void
    {
        $card = $this->stateCard($this->stateProject('state-blocker'), 'tech-design');
        $blocker = $this->holdByBlocker($card, '2026-10-02 09:10:00');

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::Waiting, $state?->kind);
        self::assertSame(CardStateCode::HeldByBlocker, $state->reason->code);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:10:00'), $state->reason->since);
        self::assertSame(['%number%' => $blocker->number, '%title%' => $blocker->title], $state->reason->params);
    }

    public function test_a_hold_stamp_with_no_open_blocker_left_shows_no_wait(): void
    {
        $card = $this->stateCard($this->stateProject('state-blocker-closed'), 'tech-design');
        $blocker = $this->holdByBlocker($card);
        $blocker->column = $this->column($card->project, 'done');
        $this->em()->flush();

        self::assertNull($this->stateOf($card));
    }

    public function test_the_stuck_state_wins_and_the_others_follow_in_order_of_precedence(): void
    {
        $card = $this->stateCard($this->stateProject('state-precedence'), 'tech-design');
        $this->holdByBlocker($card);
        $this->requestWork($card);
        $this->askOwner($card);
        $this->pauseCard($card);

        $state = $this->stateOf($card);

        self::assertSame(CardStateKind::Stuck, $state?->kind);
        self::assertSame(CardStateCode::Paused, $state->reason->code);
        self::assertSame(
            [CardStateCode::OpenQuestion, CardStateCode::WorkRequested, CardStateCode::HeldByBlocker],
            array_map(static fn ($reason) => $reason->code, $state->others),
        );
    }

    public function test_a_card_keeps_the_earliest_reason_of_a_kind_it_has_twice(): void
    {
        $card = $this->stateCard($this->stateProject('state-two-requests'));
        $this->requestWork($card, 'implement', '2026-10-02 09:30:00');
        $this->requestWork($card, 'review', '2026-10-02 09:00:00');

        $state = $this->stateOf($card);

        self::assertNotNull($state);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00:00'), $state->reason->since);
        self::assertSame([], $state->others);
    }

    /** @param array<string, CardRunWarning> $warnings */
    private function stateOf(Card $card, array $warnings = []): ?CardState
    {
        $this->em()->clear();
        $card = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card is stored.');

        $pullRequests = self::getContainer()->get(CardPullRequestStates::class);
        $states = self::getContainer()->get(CardStates::class);
        self::assertInstanceOf(CardPullRequestStates::class, $pullRequests);
        self::assertInstanceOf(CardStates::class, $states);

        return $states->forCards($card->project, [$card], $pullRequests->forCards([$card]), $warnings)[(string) $card->id] ?? null;
    }

    private function approvedPullRequest(Card $card, \DateTimeImmutable $readySince): \App\Module\Forge\Entity\ForgePullRequest
    {
        return $this->linkPullRequest($card, [
            'checks' => PullRequestChecks::Passed,
            'mergeability' => PullRequestMergeability::Mergeable,
            'review' => PullRequestReview::Approved,
            'approvalId' => 'review1',
            'approvalSha' => 'head1',
            'coveredSha' => 'head1',
            'readyToMerge' => true,
        ], $readySince);
    }
}
