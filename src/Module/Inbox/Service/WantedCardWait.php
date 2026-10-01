<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Review\Entity\Document;
use Symfony\Component\Uid\Uuid;

/** A wait that a card has now, before the reconciler compares it with the stored waits. */
final readonly class WantedCardWait
{
    public const int MAX_OUTPUT_LINE_LENGTH = 140;

    private function __construct(
        public InboxCardWaitTrigger $trigger,
        public string $reason,
        public ?Document $document = null,
        public ?int $versionNumber = null,
        public ?Uuid $runId = null,
        public ?Uuid $pullRequestId = null,
        public ?string $headSha = null,
    ) {
    }

    public static function forPullRequestReady(Uuid $pullRequestId, int $number, string $headSha): self
    {
        $reason = \sprintf('Pull request #%d waits for review', $number);

        return new self(InboxCardWaitTrigger::PullRequestReady, mb_substr($reason, 0, InboxCardWait::MAX_REASON_LENGTH), pullRequestId: $pullRequestId, headSha: $headSha);
    }

    public static function forPullRequestChangedAfterApproval(Uuid $pullRequestId, int $number, string $headSha): self
    {
        $reason = \sprintf('Pull request #%d has new commits after your approval (%s)', $number, substr($headSha, 0, 7));

        return new self(InboxCardWaitTrigger::PullRequestReady, mb_substr($reason, 0, InboxCardWait::MAX_REASON_LENGTH), pullRequestId: $pullRequestId, headSha: $headSha);
    }

    public static function forPullRequestFixStopped(Uuid $pullRequestId, int $number, string $headSha, ?string $blockedReason): self
    {
        $reason = \sprintf('Pull request #%d: fix loop stopped', $number);
        if (null !== $blockedReason && '' !== $blockedReason) {
            $reason .= \sprintf(' (%s)', $blockedReason);
        }

        return new self(InboxCardWaitTrigger::PullRequestFixStopped, mb_substr($reason, 0, InboxCardWait::MAX_REASON_LENGTH), pullRequestId: $pullRequestId, headSha: $headSha);
    }

    public static function forDocument(Document $document, int $versionNumber): self
    {
        $reason = \sprintf('%s in review, version %d', $document->title, $versionNumber);

        return new self(InboxCardWaitTrigger::DocumentInReview, mb_substr($reason, 0, InboxCardWait::MAX_REASON_LENGTH), $document, $versionNumber);
    }

    /** The reason names the first line of the run output that is not blank. */
    public static function forRun(InboxCardWaitTrigger $trigger, Uuid $runId, string $output): self
    {
        $label = match ($trigger) {
            InboxCardWaitTrigger::RunBlocked => 'Run blocked',
            InboxCardWaitTrigger::RunGaveUp => 'Run gave up',
            InboxCardWaitTrigger::RunWaitingForPerson => 'Run waits for a person',
            InboxCardWaitTrigger::DocumentInReview,
            InboxCardWaitTrigger::PullRequestReady,
            InboxCardWaitTrigger::PullRequestFixStopped => throw new \InvalidArgumentException('A run wait has a run trigger.'),
        };
        $reason = $label;
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $reason .= ': '.mb_substr($line, 0, self::MAX_OUTPUT_LINE_LENGTH);
                break;
            }
        }

        return new self($trigger, mb_substr($reason, 0, InboxCardWait::MAX_REASON_LENGTH), runId: $runId);
    }

    public function key(): string
    {
        return InboxCardWait::computeKey($this->trigger, $this->document?->id, $this->versionNumber, $this->runId, $this->pullRequestId, $this->headSha);
    }
}
