<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
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
    public function export(User $user): iterable
    {
        foreach ($this->siteReviewComments->findByOwner($user) as $comment) {
            yield [
                'project' => $comment->project->name,
                'body' => $comment->body,
                'anchors' => array_values(array_map(
                    static fn (SiteReviewCommentAnchor $anchor): array => [
                        'selector' => $anchor->selector,
                        'text' => $anchor->text,
                        'quote' => $anchor->quote,
                        'quotePrefix' => $anchor->quotePrefix,
                        'quoteSuffix' => $anchor->quoteSuffix,
                    ],
                    $comment->anchors->toArray(),
                )),
                'url' => $comment->url,
                'status' => $comment->status->value,
                'createdAt' => $comment->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
