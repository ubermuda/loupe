<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class SubmitReviewHandler
{
    public function __construct(
        private SiteReviewRepository $siteReviews,
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private SiteReviewTopicBuilder $topicBuilder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubmitReviewCommand $command): SiteReview
    {
        $review = $this->siteReviews->findOneInProgress($command->site);
        if (null === $review || $review->comments->isEmpty()) {
            throw new DomainErrors(['review' => 'site_review.error.nothing_to_submit']);
        }

        $review->markSubmitted();
        $this->em->flush();

        $this->publish($review);

        $this->logger->info('site_review.review.submitted', [
            'siteId' => (string) $command->site->id,
            'reviewId' => (string) $review->id,
            'commentCount' => $review->comments->count(),
        ]);

        return $review;
    }

    private function publish(SiteReview $review): void
    {
        $site = $review->site;
        $topic = $this->topicBuilder->forSite(
            $site->id ?? throw new \LogicException('Managed site has no id.'),
        );

        $urls = array_values(array_unique(
            array_map(static fn (SiteReviewComment $c): string => $c->url, $review->comments->toArray()),
        ));

        $payload = json_encode([
            'type' => 'site_review.submitted',
            'siteId' => (string) $site->id,
            'siteName' => $site->name,
            'reviewId' => (string) $review->id,
            'commentCount' => $review->comments->count(),
            'urls' => $urls,
            'submittedAt' => $review->submittedAt?->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR);

        // Best-effort: a down or slow hub must never fail an already-persisted
        // submit — that would surface as a 500 and provoke a duplicate resubmit.
        try {
            $this->hub->publish(new Update($topic, $payload, true));
        } catch (\Throwable $e) {
            $this->logger->warning('site_review.review.publish_failed', [
                'reviewId' => (string) $review->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
