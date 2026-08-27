<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Review\Repository\ReviewRepository;

final readonly class ReviewExporter implements UserDataExporterInterface
{
    public function __construct(
        private ReviewRepository $reviews,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'reviews.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->reviews->streamByReviewer($user) as $review) {
            yield [
                'document' => $review->version->document->title,
                'versionNumber' => $review->version->versionNumber,
                'verdict' => $review->verdict->value,
                'submittedAt' => $review->submittedAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
