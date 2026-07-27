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
 * Outbox row for the Mercure update a submit publishes. Persisted in the same
 * flush as the Draft→Pending transition, so the event survives even if the
 * publish attempt that follows never runs (process crash) or fails (hub
 * unreachable) — it is never only in-memory. `publishedAt` marks a confirmed
 * publish; a null value is a durable record of a not-yet-confirmed one.
 * `sequence` is DB-generated (identity column) and doubles as the Mercure SSE
 * event id, giving subscribers a monotonic `Last-Event-ID` to resume from.
 */
#[ORM\Entity(repositoryClass: SiteReviewEventRepository::class)]
#[ORM\Table(name: 'site_review_events')]
class SiteReviewEvent
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::BIGINT, unique: true, insertable: false, updatable: false, generated: 'INSERT')]
    public private(set) ?string $sequence = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $topic,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $payload,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function markPublished(): void
    {
        $this->publishedAt = new \DateTimeImmutable();
    }
}
