<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\AgentPush;
use App\Outbox\Repository\OutboxEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Replays events whose publish never landed, because the hub was unreachable or
 * the process died between the outbox write and the publish.
 */
final readonly class DrainOutboxHandler
{
    /**
     * How long a claimed event stays off-limits to other workers. Longer than a
     * publish takes, shorter than the interval between drains, so a worker that
     * dies mid-batch releases its claim before the next pass.
     */
    private const string LEASE = 'PT5M';

    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private LoggerInterface $logger,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function __invoke(DrainOutboxCommand $command): OutboxDrainResult
    {
        // Claims nothing rather than claiming and failing. A drain with push off
        // would lease every due row and record an attempt, so turning push back
        // on would find them backed off rather than ready.
        if (!$this->featureFlags->isEnabled(AgentPush::FLAG)) {
            $this->logger->debug('outbox.drain_skipped_push_disabled');

            return new OutboxDrainResult(0, 0);
        }

        $now = new \DateTimeImmutable();
        $events = $this->outboxEvents->claimDueForPublish(
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
                $this->logger->warning('outbox.publish_failed', [
                    'eventId' => (string) $event->id,
                    'projectId' => (string) $event->project->id,
                    'type' => $event->type,
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
            $this->logger->info('outbox.drained', [
                'published' => $published,
                'failed' => $failed,
            ]);
        }

        return new OutboxDrainResult($published, $failed);
    }
}
