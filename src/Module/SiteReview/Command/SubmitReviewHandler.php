<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class SubmitReviewHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private SiteReviewTopicBuilder $topicBuilder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubmitReviewCommand $command): int
    {
        $flippedCount = $this->siteReviewComments->markDraftsPendingForProject($command->project);
        if (0 === $flippedCount) {
            throw new DomainErrors(['review' => 'site_review.error.nothing_to_submit']);
        }

        $topic = $this->topicBuilder->forProject(
            $command->project->id ?? throw new \LogicException('Managed project has no id.'),
        );
        $payload = json_encode(['type' => 'site_review.submitted'], \JSON_THROW_ON_ERROR);
        $event = new SiteReviewEvent($command->project, $topic, $payload);
        $this->em->persist($event);
        $this->em->flush();

        $this->publish($event);

        $this->logger->info('site_review.review.submitted', [
            'projectId' => (string) $command->project->id,
            'commentCount' => $flippedCount,
        ]);

        return $flippedCount;
    }

    private function publish(SiteReviewEvent $event): void
    {
        // Not retried: the hub may accept an update and still throw, and a
        // duplicate nudge is harmless (the Draft→Pending transition is itself
        // the dedup — a redundant pull just finds nothing new), but a second
        // publish is still needless load. A failed publish leaves $event
        // unpublished for a human to replay.
        try {
            $this->hub->publish(new Update($event->topic, $event->payload, true, id: $event->sequence));
        } catch (\Throwable $e) {
            $this->logger->error('site_review.review.publish_failed', [
                'projectId' => (string) $event->project->id,
                'topic' => $event->topic,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $event->markPublished();
        $this->em->flush();
    }
}
