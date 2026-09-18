<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReviewTargetKind;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxItemCloser;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class SubmitInboxPullRequestReviewHandler
{
    public function __construct(
        private InboxItemCloser $closer,
        private InboxReviewRepository $inboxReviews,
        private CardPullRequestRepository $cardPullRequests,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SubmitInboxPullRequestReviewCommand $command): InboxItem
    {
        $item = $command->item;
        if (InboxItemKind::Review !== $item->kind) {
            throw new DomainErrors(['verdict' => 'inbox.review.error.not_a_review']);
        }
        $verdict = InboxReviewVerdict::tryFrom($command->verdict)
            ?? throw new DomainErrors(['verdict' => 'review.document.flash.verdict_invalid']);
        $note = trim($command->note ?? '');
        if (InboxReviewVerdict::ChangesRequested === $verdict && '' === $note) {
            throw new DomainErrors(['note' => 'review.document.flash.note_required']);
        }

        $this->closer->respond($item, InboxItemState::Done, 'verdict', function (InboxItem $item) use ($command, $verdict, $note): ?array {
            $review = $this->inboxReviews->findOneBy(['item' => $item]);
            if (null === $review || InboxReviewTargetKind::PullRequest !== $review->targetKind) {
                return ['verdict' => 'inbox.review.error.not_a_pull_request'];
            }
            $url = null === $review->pullRequest ? null : $this->cardPullRequests->findUrlForUpdate($review->pullRequest);
            if (null === $url) {
                return ['expectedUrl' => 'inbox.review.error.target_unavailable'];
            }
            if ($url !== $command->expectedUrl) {
                return ['expectedUrl' => 'inbox.review.error.target_changed'];
            }

            $review->targetLabel = $url;
            $review->verdict = $verdict;
            $review->note = '' === $note ? null : $note;
            $review->reviewer = $command->reviewer;
            $review->submittedAt = new \DateTimeImmutable();

            return null;
        });

        $this->auditor->record(
            'inbox.review_submitted',
            AuditOutcome::Success,
            ['itemId' => (string) $item->id, 'projectId' => (string) $item->project->id, 'verdict' => $verdict->value],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }
}
