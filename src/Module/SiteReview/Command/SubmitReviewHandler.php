<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use App\Module\SiteReview\SiteReviewPush;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class SubmitReviewHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private SiteReviewTopicBuilder $topicBuilder,
        private LoggerInterface $logger,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function __invoke(SubmitReviewCommand $command): int
    {
        // Decided here rather than around the publish, so the row is written
        // unforwardable: the outbox treats that as settled, and enabling push
        // later replays no backlog. A missing widget token falls back to not
        // forwarding, which is the safe side.
        $forwardable = ($command->project->widgetToken->forwardsToAgent ?? false)
            && $this->featureFlags->isEnabled(SiteReviewPush::FLAG);

        // The flip is a bulk DQL update — immediate, not deferred to flush — so
        // without this transaction a later failure leaves comments Pending with
        // no event to replay.
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
            // pull them with site_review_get whenever the owner asks it to. What is
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
        // Not retried inline: the hub may accept an update and still throw, and a
        // duplicate nudge is harmless (the Draft→Pending transition is itself
        // the dedup — a redundant pull just finds nothing new), but a second
        // publish would delay the visitor's response for no gain. Recording the
        // failure on the row hands it to the outbox drain, and is what makes the
        // undelivered-events pages show a reason rather than an untouched row.
        try {
            $this->hub->publish(new Update($event->topic, $event->payload, true, id: $event->sequence));
        } catch (\Throwable $e) {
            $event->recordPublishFailure($e->getMessage(), new \DateTimeImmutable());
            $this->em->flush();

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
