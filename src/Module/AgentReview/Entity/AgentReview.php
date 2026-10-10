<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** What an agent found when it reviewed one head of the pull request of a card. */
#[ORM\Entity(repositoryClass: AgentReviewRepository::class)]
#[ORM\Index(name: 'idx_agent_reviews_pull_request_head', columns: ['pull_request_id', 'head_sha'])]
#[ORM\Table(name: 'agent_reviews')]
class AgentReview
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** @var list<array{path: ?string, startLine: ?int, endLine: ?int, severity: string, title: string, body: string, category?: string}> */
    #[ORM\Column(type: Types::JSON)]
    public array $findings;

    /** The forge's id of the check run that shows this review. Each review gets its own run. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $checkRunId = null;

    /** How many line notes the run of $checkRunId holds, so a retry sends only the rest. */
    #[ORM\Column]
    public int $annotationsPosted = 0;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $postedAt = null;

    /**
     * @param list<AgentReviewFinding> $findings
     */
    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: ForgePullRequest::class)]
        public readonly ForgePullRequest $pullRequest,

        #[ORM\Column(length: 64)]
        public readonly string $headSha,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $summary,

        #[ORM\Column(length: 20, enumType: AgentReviewConclusion::class)]
        public readonly AgentReviewConclusion $conclusion,
        array $findings,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $workerRunId = null,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $workRequestId = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->findings = array_map(static fn (AgentReviewFinding $finding): array => $finding->toArray(), $findings);
    }

    /** @return list<AgentReviewFinding> */
    public function findings(): array
    {
        return array_map(AgentReviewFinding::fromArray(...), $this->findings);
    }
}
