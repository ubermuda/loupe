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
        // The flip and the outbox row commit together or not at all. The flip is
        // a bulk DQL update, which executes immediately rather than at flush —
        // so without a transaction a later failure would leave the comments
        // Pending with no event to replay, which is precisely what the outbox
        // exists to prevent.
        // Whether this review reaches the owner's agent is a property of the
        // credential that submitted it, and the widget token is bound one-to-one
        // to the project (the resolver matched the project BY that token), so the
        // project's own widget token is the submitting one. A project with no
        // widget token cannot reach this handler at all — treating that as "do
        // not forward" keeps the fallback on the safe side.
        $forwardable = $command->project->widgetToken?->forwardsToAgent ?? false;

        [$flippedCount, $event] = $this->em->wrapInTransaction(function () use ($command, $forwardable): array {
            $flippedCount = $this->siteReviewComments->markDraftsPendingForProject($command->project);
            if (0 === $flippedCount) {
                throw new DomainErrors(['review' => 'site_review.error.nothing_to_submit']);
            }

            $topic = $this->topicBuilder->forProject(
                $command->project->id ?? throw new \LogicException('Managed project has no id.'),
            );
            $payload = json_encode(['type' => 'site_review.submitted'], \JSON_THROW_ON_ERROR);
            $event = new SiteReviewEvent($command->project, $topic, $payload, $forwardable);
            $this->em->persist($event);
            $this->em->flush();

            return [$flippedCount, $event];
        });

        if ($forwardable) {
            $this->publish($event);
        } else {
            // Collect-only token: the comments are Pending and the agent can still
            // pull them with get_site_review whenever the owner asks it to. What is
            // withheld is the unsolicited nudge, which is what would otherwise let
            // any visitor of a public page drive the owner's agent.
            $this->logger->info('site_review.review.forwarding_suppressed', [
                'projectId' => (string) $command->project->id,
                'eventId' => (string) $event->id,
            ]);
        }

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
