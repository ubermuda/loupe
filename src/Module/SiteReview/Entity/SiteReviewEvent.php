<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Outbox row for a Mercure update pushed to a project's agent. Persisted in the
 * same flush as whatever triggered it, so the event survives even if the publish
 * attempt that follows never runs (process crash) or fails (hub unreachable) —
 * it is never only in-memory. `publishedAt` marks a confirmed publish; a null
 * value is a durable record of a not-yet-confirmed one.
 * `sequence` is DB-generated (identity column) and doubles as the Mercure SSE
 * event id, giving subscribers a monotonic `Last-Event-ID` to resume from.
 */
#[ORM\Entity(repositoryClass: SiteReviewEventRepository::class)]
#[ORM\Index(name: 'idx_site_review_events_drain', columns: ['published_at', 'forwardable', 'next_attempt_at'])]
#[ORM\Table(name: 'site_review_events')]
class SiteReviewEvent
{
    private const int MAX_BACKOFF_MINUTES = 60;
    private const int ERROR_LENGTH_LIMIT = 500;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::BIGINT, unique: true, insertable: false, updatable: false, generated: 'INSERT')]
    public private(set) ?string $sequence = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    /**
     * How many publish attempts have been made and rejected. Counted on caught
     * failures only, so a process that dies mid-publish leaves it untouched and
     * the row retries at the next tick rather than backing off.
     */
    #[ORM\Column(options: ['default' => 0])]
    public int $publishAttempts = 0;

    /** Earliest moment the drain may claim this row. Null means due now. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $lastPublishError = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $topic,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $payload,

        /**
         * Whether this event may be delivered to the agent at all. False when the
         * widget token that submitted the review is collect-only
         * (ApiToken::$forwardsToAgent). The row is still written — it is also the
         * ledger the submitted-review counts are drawn from — but it must never be
         * published, so anything that drains unpublished events has to filter on
         * this as well as on publishedAt; a null publishedAt alone does not mean
         * "still owed to the agent".
         */
        #[ORM\Column(options: ['default' => true])]
        public readonly bool $forwardable = true,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function markPublished(): void
    {
        $this->publishedAt = new \DateTimeImmutable();
    }

    /**
     * Backs the row off after a rejected publish: the wait doubles per attempt
     * and stops growing at an hour, so a hub that has been down for a day does
     * not push the next attempt into next week. There is no attempt ceiling —
     * an outbox that gives up on a row is one that records a failure it can
     * never replay, which is the thing this drain exists to prevent.
     */
    public function recordPublishFailure(string $error, \DateTimeImmutable $now): void
    {
        ++$this->publishAttempts;
        $this->lastPublishError = mb_substr($error, 0, self::ERROR_LENGTH_LIMIT);
        $this->nextAttemptAt = $now->add(new \DateInterval(sprintf(
            'PT%dM',
            min(2 ** min($this->publishAttempts - 1, 6), self::MAX_BACKOFF_MINUTES),
        )));
    }
}
