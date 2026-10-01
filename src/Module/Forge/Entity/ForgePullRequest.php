<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

use App\Doctrine\Type\MicrosecondDateTimeImmutableType;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
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

    public const int ANNOUNCED_REVIEW_LIMIT = 50;

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

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $changesRequestedSha = null;

    /** Kept to the microsecond, because the refresh skip rule compares it with the request time of a message. */
    #[ORM\Column(type: MicrosecondDateTimeImmutableType::NAME, nullable: true, columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL')]
    public ?\DateTimeImmutable $refreshedAt = null;

    /** The reads in a row that found the mergeability unknown. */
    #[ORM\Column]
    public int $refreshAttempts = 0;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $nextRefreshAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $mergedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $approvalSha = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $approvalId = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $coveredSha = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $defaultBranch = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $headParents = [];

    /** The head Loupe asked the forge to bring up to date with its base. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $syncFromSha = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $syncRequestedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $syncFailedReason = null;

    /** The last head that a sync by Loupe produced. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $syncedSha = null;

    /** @var list<string> the newest forge ids of the reviews whose verdict went out, so a redelivered review is announced once */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $announcedReviewIds = [];

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

    public function hasAnnouncedReview(string $reviewId): bool
    {
        return \in_array($reviewId, $this->announcedReviewIds, true);
    }

    public function recordAnnouncedReview(string $reviewId): void
    {
        $ids = array_values(array_diff($this->announcedReviewIds, [$reviewId]));
        $ids[] = $reviewId;
        $this->announcedReviewIds = \array_slice($ids, -self::ANNOUNCED_REVIEW_LIMIT);
    }

    public function apply(PullRequestSnapshot $snapshot): void
    {
        // Keyed on the review id, because GitHub moves the commit of a review onto a later merge from the base.
        $approvalChanged = $this->approvalId !== $snapshot->approvalId;
        $headMoved = $this->headSha !== $snapshot->headSha;
        if ($approvalChanged) {
            $this->coveredSha = $snapshot->approvalSha;
            $this->syncFailedReason = null;
        }
        // After the approval reset, so an approval of the head Loupe asked to update still follows the sync in one read.
        if ($headMoved && null !== $this->syncFromSha && ($snapshot->headParents[0] ?? null) === $this->syncFromSha && $this->coveredSha === $this->syncFromSha) {
            // The merge commit of a sync from the covered head adds only base changes, so the approval still covers it.
            $this->coveredSha = $this->syncedSha = $snapshot->headSha;
        }
        // A sync in flight keeps its marker through a new approval, so its merge commit is still recognised.
        if ($headMoved) {
            $this->syncFromSha = null;
            $this->syncRequestedAt = null;
            $this->syncFailedReason = null;
        }
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
        $this->changesRequestedSha = $snapshot->changesRequestedSha;
        $this->openedAt = $snapshot->openedAt;
        $this->mergedAt = $snapshot->mergedAt;
        $this->approvedAt = $snapshot->approvedAt;
        $this->approvalSha = $snapshot->approvalSha;
        $this->approvalId = $snapshot->approvalId;
        $this->defaultBranch = $snapshot->defaultBranch;
        $this->headParents = $snapshot->headParents;
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
            $this->changesRequestedSha,
            $this->openedAt,
            $this->mergedAt,
            $this->approvedAt,
            $this->approvalSha,
            $this->defaultBranch,
            $this->headParents,
            $this->approvalId,
        );
    }
}
