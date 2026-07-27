<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;

final readonly class SiteReviewExporter implements UserDataExporterInterface
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
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
        foreach ($this->siteReviewComments->findByOwner($user) as $comment) {
            $rows[] = [
                'project' => $comment->project->name,
                'body' => $comment->body,
                'selector' => $comment->selector,
                'text' => $comment->text,
                'url' => $comment->url,
                'status' => $comment->status->value,
                'createdAt' => $comment->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }
}
