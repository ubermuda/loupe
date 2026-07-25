<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\DataExport\UserDataExporterInterface;
use App\Module\Account\Entity\User;
use App\Module\Review\Repository\ReviewRepository;

final readonly class ReviewExporter implements UserDataExporterInterface
{
    public function __construct(private ReviewRepository $reviews)
    {
    }

    #[\Override]
    public function filename(): string
    {
        return 'reviews.json';
    }

    #[\Override]
    public function export(User $user): array
    {
        $rows = [];
        foreach ($this->reviews->findByReviewer($user) as $review) {
            $rows[] = [
                'document' => $review->version->document->title,
                'versionNumber' => $review->version->versionNumber,
                'verdict' => $review->verdict->value,
                'submittedAt' => $review->submittedAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }
}
