<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewBatch;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class SubmitBatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private SiteReviewTopicBuilder $topicBuilder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubmitBatchCommand $command): SiteReviewBatch
    {
        $batch = new SiteReviewBatch($command->actor);
        foreach ($command->comments as $c) {
            $batch->addComment($c['body'], $c['selector'], $c['text'], $c['url']);
        }

        $this->em->persist($batch);
        $this->em->flush();

        $this->publish($batch);

        return $batch;
    }

    private function publish(SiteReviewBatch $batch): void
    {
        $topic = $this->topicBuilder->forUser(
            $batch->owner->id ?? throw new \LogicException('Batch owner has no id after flush.'),
        );

        $urls = array_values(array_unique(
            array_map(static fn (SiteReviewComment $c): string => $c->url, $batch->comments->toArray()),
        ));

        $payload = json_encode([
            'type' => 'site_review.submitted',
            'batchId' => (string) $batch->id,
            'commentCount' => $batch->comments->count(),
            'urls' => $urls,
            'createdAt' => $batch->createdAt->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR);

        // Best-effort: a down or slow hub must never fail an already-persisted
        // submit — that would surface as a 500 and provoke a duplicate resubmit.
        try {
            $this->hub->publish(new Update($topic, $payload, true));
            $this->logger->info('site_review.batch.published', ['batchId' => (string) $batch->id]);
        } catch (\Throwable $e) {
            $this->logger->warning('site_review.batch.publish_failed', [
                'batchId' => (string) $batch->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
