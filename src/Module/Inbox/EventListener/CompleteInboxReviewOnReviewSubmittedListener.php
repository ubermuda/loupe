<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Inbox\Service\InboxOpenCountPublisher;
use App\Module\Review\Event\ReviewSubmitted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class CompleteInboxReviewOnReviewSubmittedListener
{
    public function __construct(
        private InboxReviewRepository $inboxReviews,
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxOpenCountPublisher $openCount,
    ) {
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        $result = $event->review;
        $reviews = $this->inboxReviews->findOpenForDocument($result->version->document);
        $this->inboxItems->reloadChangeableColumns(array_map(static fn ($review) => $review->item, $reviews));
        foreach ($reviews as $review) {
            if (!$this->closer->close($review->item, InboxItemState::Done, null, $result->submittedAt, InboxEventType::ACTOR_HUMAN)) {
                continue;
            }

            $review->verdict = InboxReviewVerdict::from($result->verdict->value);
            $review->note = $result->note;
            $review->submittedAt = $result->submittedAt;
            $review->reviewer = $result->reviewer;
            $review->reviewedVersionNumber = $result->version->versionNumber;
            $review->documentReview = $result;
            $this->openCount->countChanged($review->item->project);
        }
    }
}
