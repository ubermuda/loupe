<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use App\Doctrine\Type\MicrosecondDateTimeImmutableType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The last state Loupe read of one pull request that a card of one project
 * links. The repository is stored lower-case, so one pull request has one row
 * whatever case a link spelled it in.
 */
#[ORM\Entity(repositoryClass: ForgePullRequestRepository::class)]
#[ORM\Table(name: 'forge_pull_requests')]
#[ORM\UniqueConstraint(name: self::KEY_CONSTRAINT, columns: ['project_id', 'forge', 'repository', 'number'])]
class ForgePullRequest
{
    public const string KEY_CONSTRAINT = 'uniq_forge_pull_requests_project_forge_repository_number';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: PullRequestState::class)]
    public PullRequestState $state = PullRequestState::Open;

    #[ORM\Column]
    public bool $draft = false;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $headSha = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $baseBranch = null;

    #[ORM\Column(length: 20, enumType: PullRequestChecks::class)]
    public PullRequestChecks $checks = PullRequestChecks::Pending;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $checksSha = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public array $failedChecks = [];

    #[ORM\Column(length: 20, enumType: PullRequestMergeability::class)]
    public PullRequestMergeability $mergeability = PullRequestMergeability::Unknown;

    #[ORM\Column(length: 20, enumType: PullRequestReview::class)]
    public PullRequestReview $review = PullRequestReview::None;

    #[ORM\Column]
    public bool $readyToMerge = false;

    /** Kept to the microsecond, because the refresh skip rule compares it with the request time of a message. */
    #[ORM\Column(type: MicrosecondDateTimeImmutableType::NAME, nullable: true, columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL')]
    public ?\DateTimeImmutable $refreshedAt = null;

    /** The reads in a row that found the mergeability unknown. */
    #[ORM\Column]
    public int $refreshAttempts = 0;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $nextRefreshAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** The forge's slug, such as `github`. */
        #[ORM\Column(length: 50)]
        public readonly string $forge,

        #[ORM\Column(length: 255)]
        public string $repository,

        #[ORM\Column]
        public readonly int $number,
    ) {
        $this->repository = mb_strtolower($repository);
    }

    public function apply(PullRequestSnapshot $snapshot): void
    {
        $this->state = $snapshot->state;
        $this->draft = $snapshot->draft;
        $this->headSha = $snapshot->headSha;
        $this->baseBranch = $snapshot->baseBranch;
        $this->checks = $snapshot->checks;
        $this->checksSha = $snapshot->checksSha;
        $this->failedChecks = $snapshot->failedChecks;
        $this->mergeability = $snapshot->mergeability;
        $this->review = $snapshot->review;
        $this->readyToMerge = $snapshot->readyToMerge;
    }

    public function snapshot(): PullRequestSnapshot
    {
        return new PullRequestSnapshot(
            $this->state,
            $this->draft,
            $this->headSha,
            $this->baseBranch,
            $this->checks,
            $this->checksSha,
            $this->failedChecks,
            $this->mergeability,
            $this->review,
            $this->readyToMerge,
        );
    }
}
