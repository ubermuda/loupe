<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewEvent;
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
        $review = $this->siteReviews->findOneInProgress($command->project);
        if (null === $review || $review->comments->isEmpty()) {
            throw new DomainErrors(['review' => 'site_review.error.nothing_to_submit']);
        }

        $review->markSubmitted();
        $event = $this->buildEvent($review);
        $this->em->persist($event);
        $this->em->flush();

        $this->publish($event);

        $this->logger->info('site_review.review.submitted', [
            'projectId' => (string) $command->project->id,
            'reviewId' => (string) $review->id,
            'commentCount' => $review->comments->count(),
        ]);

        return $review;
    }

    private function buildEvent(SiteReview $review): SiteReviewEvent
    {
        $project = $review->project;
        $topic = $this->topicBuilder->forProject(
            $project->id ?? throw new \LogicException('Managed project has no id.'),
        );

        $urls = array_values(array_unique(
            array_map(static fn (SiteReviewComment $c): string => $c->url, $review->comments->toArray()),
        ));

        $payload = json_encode([
            'type' => 'site_review.submitted',
            'siteId' => (string) $project->id,
            'siteName' => $project->name,
            'reviewId' => (string) $review->id,
            'commentCount' => $review->comments->count(),
            'urls' => $urls,
            'submittedAt' => $review->submittedAt?->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR);

        return new SiteReviewEvent($review, $topic, $payload);
    }

    private function publish(SiteReviewEvent $event): void
    {
        // Best-effort, published exactly once: a down or slow hub must never fail
        // an already-persisted submit — that would surface as a 500 and provoke a
        // duplicate resubmit. Deliberately not retried, either: the hub may have
        // accepted the update before the client threw, and publishing again would
        // make bridge subscribers inject the same review twice — the bridge CLI
        // does not dedupe on the review id carried as the update's event id. A
        // failed publish leaves $event durably unpublished (see the entity) for a
        // human to replay by hand; it is not auto-redelivered.
        try {
            $this->hub->publish(new Update($event->topic, $event->payload, true, id: (string) $event->review->id));
            $event->markPublished();
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('site_review.review.publish_failed', [
                'reviewId' => (string) $event->review->id,
                'topic' => $event->topic,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
