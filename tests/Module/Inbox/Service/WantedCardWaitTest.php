<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Service\WantedCardWait;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WantedCardWaitTest extends TestCase
{
    public function test_a_ready_pull_request_waits_for_review(): void
    {
        $pullRequestId = Uuid::v7();
        $headSha = str_repeat('a1', 20);

        $wait = WantedCardWait::forPullRequestReady($pullRequestId, 640, $headSha);

        self::assertSame(InboxCardWaitTrigger::PullRequestReady, $wait->trigger);
        self::assertSame('Pull request #640 waits for review', $wait->reason);
        self::assertSame($pullRequestId, $wait->pullRequestId);
        self::assertSame($headSha, $wait->headSha);
        self::assertNull($wait->document);
        self::assertNull($wait->runId);
        self::assertSame(InboxCardWait::computeKey(InboxCardWaitTrigger::PullRequestReady, pullRequestId: $pullRequestId, headSha: $headSha), $wait->key());
    }

    public function test_a_stopped_fix_loop_names_its_blocked_reason(): void
    {
        $pullRequestId = Uuid::v7();
        $headSha = str_repeat('b2', 20);

        $wait = WantedCardWait::forPullRequestFixStopped($pullRequestId, 640, $headSha, 'checks-failed');

        self::assertSame(InboxCardWaitTrigger::PullRequestFixStopped, $wait->trigger);
        self::assertSame('Pull request #640: fix loop stopped (checks-failed)', $wait->reason);
        self::assertSame($pullRequestId, $wait->pullRequestId);
        self::assertSame($headSha, $wait->headSha);
        self::assertSame(InboxCardWait::computeKey(InboxCardWaitTrigger::PullRequestFixStopped, pullRequestId: $pullRequestId, headSha: $headSha), $wait->key());
    }

    #[TestWith([null])]
    #[TestWith([''])]
    public function test_a_stopped_fix_loop_without_a_blocked_reason_names_none(?string $blockedReason): void
    {
        $wait = WantedCardWait::forPullRequestFixStopped(Uuid::v7(), 640, 'abc123', $blockedReason);

        self::assertSame('Pull request #640: fix loop stopped', $wait->reason);
    }

    public function test_a_long_blocked_reason_fits_the_reason_column(): void
    {
        $wait = WantedCardWait::forPullRequestFixStopped(Uuid::v7(), 640, 'abc123', str_repeat('x', 300));

        self::assertSame(InboxCardWait::MAX_REASON_LENGTH, mb_strlen($wait->reason));
    }

    #[TestWith([InboxCardWaitTrigger::PullRequestReady])]
    #[TestWith([InboxCardWaitTrigger::PullRequestFixStopped])]
    #[TestWith([InboxCardWaitTrigger::DocumentInReview])]
    #[TestWith([InboxCardWaitTrigger::CardPaused])]
    public function test_a_run_wait_refuses_a_trigger_that_is_not_a_run_trigger(InboxCardWaitTrigger $trigger): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WantedCardWait::forRun($trigger, Uuid::v7(), 'output');
    }

    public function test_a_pause_waits_with_its_reason_code_and_is_keyed_by_the_pause(): void
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed'), 'Widgets');
        $card = new Card($project, new BoardColumn(project: $project, label: 'Next', slug: 'next', position: 1), 'Card', '', 1);
        $pause = new CardPause($card, $project, 'owner-review', 'review-rule', CardPauseKind::Rule, new \DateTimeImmutable());
        $pauseId = Uuid::v7();
        new \ReflectionProperty(CardPause::class, 'id')->setValue($pause, $pauseId);

        $wait = WantedCardWait::forPause($pause);

        self::assertSame(InboxCardWaitTrigger::CardPaused, $wait->trigger);
        self::assertSame('Workflow paused: owner-review', $wait->reason);
        self::assertSame($pauseId, $wait->pauseId);
        self::assertNull($wait->runId);
        self::assertSame(InboxCardWait::computeKey(InboxCardWaitTrigger::CardPaused, pauseId: $pauseId), $wait->key());
    }
}
