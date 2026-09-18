<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\SiteReview\Repository\SiteReviewReplyRepository;

final readonly class SiteReviewReplyExporter implements UserDataExporterInterface
{
    public function __construct(
        private SiteReviewReplyRepository $siteReviewReplies,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'site_review_replies.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->siteReviewReplies->findByOwner($user) as $reply) {
            yield [
                'id' => (string) $reply->id,
                'commentId' => (string) $reply->comment->id,
                'authorId' => (string) $reply->author->id,
                'body' => $reply->body,
                'createdAt' => $reply->createdAt->format(\DATE_ATOM),
            ];
        }
    }
}
