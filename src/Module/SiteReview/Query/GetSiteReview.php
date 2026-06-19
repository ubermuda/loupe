<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Query;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewBatchRepository;
use Symfony\Component\Uid\Uuid;

final readonly class GetSiteReview
{
    public function __construct(
        private SiteReviewBatchRepository $siteReviewBatches,
    ) {
    }

    /**
     * Returns the comments of a site-review batch, scoped to the authenticated owner.
     *
     * @return array{createdAt: string, comments: list<array{url: string, selector: string, text: string, body: string}>}
     *
     * @throws BatchNotFound if no batch with the given id belongs to $owner
     */
    public function __invoke(Uuid $batchId, User $owner): array
    {
        $batch = $this->siteReviewBatches->findOneByIdAndOwner($batchId, $owner);

        if (null === $batch) {
            throw BatchNotFound::forId($batchId);
        }

        $comments = array_map(
            static fn (SiteReviewComment $comment) => [
                'url' => $comment->url,
                'selector' => $comment->selector,
                'text' => $comment->text,
                'body' => $comment->body,
            ],
            $batch->comments->toArray(),
        );

        return [
            'createdAt' => $batch->createdAt->format(\DateTimeInterface::ATOM),
            'comments' => array_values($comments),
        ];
    }
}
