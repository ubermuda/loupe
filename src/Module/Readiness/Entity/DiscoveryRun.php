<?php

declare(strict_types=1);

namespace App\Module\Readiness\Entity;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One discovery of a project: a worker reads the repository and reports what the project needs before agents work on it. */
#[ORM\Entity(repositoryClass: DiscoveryRunRepository::class)]
#[ORM\Index(name: 'idx_discovery_runs_card_created', columns: ['card_id', 'created_at'])]
#[ORM\Index(name: 'idx_discovery_runs_project_created', columns: ['project_id', 'created_at'])]
#[ORM\Table(name: 'discovery_runs')]
class DiscoveryRun
{
    public const int MAX_FAILURE_REASON_LENGTH = 1000;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: DiscoveryRunState::class)]
    public DiscoveryRunState $state = DiscoveryRunState::Requested;

    #[ORM\Column(length: self::MAX_FAILURE_REASON_LENGTH, nullable: true)]
    public ?string $failureReason = null;

    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Document::class)]
    public ?Document $reportDocument = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $endedAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    /** Answers whether the run failed now. Only a requested run fails. */
    public function fail(string $reason, \DateTimeImmutable $at): bool
    {
        if (DiscoveryRunState::Requested !== $this->state) {
            return false;
        }
        $this->state = DiscoveryRunState::Failed;
        $this->failureReason = mb_substr($reason, 0, self::MAX_FAILURE_REASON_LENGTH);
        $this->endedAt = $at;

        return true;
    }
}
