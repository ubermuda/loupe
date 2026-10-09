<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\CardPause;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Review\Entity\Document;
use Symfony\Component\Uid\Uuid;

/** A wait that a card has now, before the reconciler compares it with the stored waits. */
final readonly class WantedCardWait
{
    private function __construct(
        public InboxCardWaitTrigger $trigger,
        public InboxCardWaitType $type,
        public InboxCardWaitReason $reason,
        public ?Document $document = null,
        public ?int $versionNumber = null,
        public ?Uuid $runId = null,
        public ?Uuid $pullRequestId = null,
        public ?string $headSha = null,
        public ?Uuid $pauseId = null,
    ) {
    }

    public static function forPullRequestReady(Uuid $pullRequestId, string $headSha): self
    {
        return new self(InboxCardWaitTrigger::PullRequestReady, InboxCardWaitType::PullRequest, InboxCardWaitReason::WaitingForReview, pullRequestId: $pullRequestId, headSha: $headSha);
    }

    public static function forPullRequestChangedAfterApproval(Uuid $pullRequestId, string $headSha): self
    {
        return new self(InboxCardWaitTrigger::PullRequestReady, InboxCardWaitType::PullRequest, InboxCardWaitReason::NewCommitsAfterApproval, pullRequestId: $pullRequestId, headSha: $headSha);
    }

    public static function forDocument(Document $document, int $versionNumber): self
    {
        return new self(InboxCardWaitTrigger::DocumentInReview, InboxCardWaitType::Document, InboxCardWaitReason::WaitingForReview, $document, $versionNumber);
    }

    /** A new pause of the card is a new wait, because the key names the pause. */
    public static function forPause(CardPause $pause): self
    {
        return new self(
            InboxCardWaitTrigger::CardPaused,
            InboxCardWaitType::CardPause,
            InboxCardWaitReason::forPause($pause->kind),
            pauseId: $pause->id ?? throw new \LogicException('A stored pause has an id.'),
        );
    }

    public static function forRun(InboxCardWaitTrigger $trigger, Uuid $runId): self
    {
        $reason = match ($trigger) {
            InboxCardWaitTrigger::RunBlocked => InboxCardWaitReason::Blocked,
            InboxCardWaitTrigger::RunGaveUp => InboxCardWaitReason::GaveUp,
            InboxCardWaitTrigger::RunWaitingForPerson => InboxCardWaitReason::WaitsForPerson,
            InboxCardWaitTrigger::DocumentInReview,
            InboxCardWaitTrigger::PullRequestReady,
            InboxCardWaitTrigger::PullRequestFixStopped,
            InboxCardWaitTrigger::CardPaused => throw new \InvalidArgumentException('A run wait has a run trigger.'),
        };

        return new self($trigger, InboxCardWaitType::WorkerRun, $reason, runId: $runId);
    }

    public function key(): string
    {
        return InboxCardWait::computeKey($this->trigger, $this->document?->id, $this->versionNumber, $this->runId, $this->pullRequestId, $this->headSha, $this->pauseId);
    }
}
