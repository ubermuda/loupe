<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\SiteReview\Repository\SiteReviewRepository;

final readonly class SiteReviewExporter implements UserDataExporterInterface
{
    public function __construct(
        private SiteReviewRepository $siteReviews,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'site_reviews.json';
    }

    #[\Override]
    public function export(User $user): array
    {
        $rows = [];
        foreach ($this->siteReviews->findByOwner($user) as $siteReview) {
            $comments = [];
            foreach ($siteReview->comments as $comment) {
                $comments[] = [
                    'position' => $comment->position,
                    'body' => $comment->body,
                    'selector' => $comment->selector,
                    'text' => $comment->text,
                    'url' => $comment->url,
                    'status' => $comment->status->value,
                    'createdAt' => $comment->createdAt->format(\DateTimeInterface::ATOM),
                ];
            }

            $rows[] = [
                'id' => (string) $siteReview->id,
                'project' => $siteReview->project->name,
                'status' => $siteReview->status->value,
                'createdAt' => $siteReview->createdAt->format(\DateTimeInterface::ATOM),
                'submittedAt' => $siteReview->submittedAt?->format(\DateTimeInterface::ATOM),
                'comments' => $comments,
            ];
        }

        return $rows;
    }
}
