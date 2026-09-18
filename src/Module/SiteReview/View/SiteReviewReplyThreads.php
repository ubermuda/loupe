<?php

declare(strict_types=1);

namespace App\Module\SiteReview\View;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewReply;

final readonly class SiteReviewReplyThreads
{
    /** @var array<string, list<SiteReviewReply>> */
    private array $byComment;

    /** @param list<SiteReviewReply> $replies */
    public function __construct(array $replies)
    {
        $byComment = [];
        foreach ($replies as $reply) {
            $byComment[(string) $reply->comment->id][] = $reply;
        }
        $this->byComment = $byComment;
    }

    /** @return list<SiteReviewReply> */
    public function forComment(SiteReviewComment $comment): array
    {
        return $this->byComment[(string) $comment->id] ?? [];
    }
}
