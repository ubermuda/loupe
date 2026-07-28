<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Replays site-review events whose publish never landed — the hub was
 * unreachable, or the process died between the outbox write and the publish.
 * Without it a failed publish is a durable record of an update nobody will
 * ever send.
 */
final readonly class DrainOutboxHandler
{
    /**
     * How long a claimed event stays off-limits to other workers. Longer than a
     * publish takes, shorter than the interval between drains, so a worker that
     * dies mid-batch releases its claim before the next pass rather than
     * stranding the rows until someone intervenes.
     */
    private const string LEASE = 'PT5M';

    public function __construct(
        private SiteReviewEventRepository $siteReviewEvents,
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DrainOutboxCommand $command): OutboxDrainResult
    {
        $now = new \DateTimeImmutable();
        $events = $this->siteReviewEvents->claimDueForPublish(
            $command->limit,
            $now,
            $now->add(new \DateInterval(self::LEASE)),
        );

        $published = 0;
        $failed = 0;

        foreach ($events as $event) {
            try {
                $this->hub->publish(new Update($event->topic, $event->payload, true, id: $event->sequence));
            } catch (\Throwable $e) {
                ++$failed;
                $event->recordPublishFailure($e->getMessage(), $now);
                $this->logger->warning('site_review.outbox.publish_failed', [
                    'eventId' => (string) $event->id,
                    'projectId' => (string) $event->project->id,
                    'attempts' => $event->publishAttempts,
                    'nextAttemptAt' => $event->nextAttemptAt?->format(\DATE_ATOM),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $event->markPublished();
            ++$published;
        }

        $this->em->flush();

        if ($published > 0 || $failed > 0) {
            $this->logger->info('site_review.outbox.drained', [
                'published' => $published,
                'failed' => $failed,
            ]);
        }

        return new OutboxDrainResult($published, $failed);
    }
}
