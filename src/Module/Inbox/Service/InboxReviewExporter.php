<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Inbox\Repository\InboxReviewRepository;

final readonly class InboxReviewExporter implements UserDataExporterInterface
{
    public function __construct(
        private InboxReviewRepository $inboxReviews,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'inbox_reviews.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->inboxReviews->findByOwner($user) as $review) {
            yield [
                'id' => (string) $review->id,
                'itemId' => (string) $review->item->id,
                'targetKind' => $review->targetKind->value,
                'targetLabel' => $review->targetLabel,
                'documentId' => $review->document?->id?->toRfc4122(),
                'pullRequestId' => $review->pullRequest?->id?->toRfc4122(),
                'verdict' => $review->verdict?->value,
                'note' => $review->note,
                'submittedAt' => $review->submittedAt?->format(\DateTimeInterface::ATOM),
                'reviewerId' => $review->reviewer?->id?->toRfc4122(),
                'reviewedVersionNumber' => $review->reviewedVersionNumber,
                'documentReviewId' => $review->documentReview?->id?->toRfc4122(),
            ];
        }
    }
}
