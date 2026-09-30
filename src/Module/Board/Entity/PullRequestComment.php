<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The comment the board posts on a pull request when the bridge queues a run to fix it. One per run. */
#[ORM\Entity(repositoryClass: PullRequestCommentRepository::class)]
#[ORM\Table(name: 'board_pull_request_comments')]
class PullRequestComment
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: PullRequestCommentState::class)]
    public PullRequestCommentState $state = PullRequestCommentState::Pending;

    #[ORM\Column(options: ['default' => 0])]
    public int $attempts = 0;

    /** The slug of the last failure, such as `permission`. */
    #[ORM\Column(length: 100, nullable: true)]
    public ?string $cause = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $postedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $failedAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(type: UuidType::NAME, unique: true)]
        public Uuid $runId,

        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $cardId,

        #[ORM\Column(length: 50)]
        public string $forge,

        #[ORM\Column(length: 255)]
        public string $repository,

        #[ORM\Column]
        public int $number,

        #[ORM\Column(length: 64, nullable: true)]
        public ?string $headSha,

        /** Why the board asked for the fix, such as `checks-failed`. */
        #[ORM\Column(length: 50, nullable: true)]
        public ?string $reason,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),

        /** The fix round of the card when the bridge queued the run. */
        #[ORM\Column(nullable: true)]
        public ?int $fixRound = null,

        /** The Forge row of the pull request when the run was queued. Forge keeps it through a rename. */
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public ?Uuid $forgePullRequestId = null,
    ) {
    }
}
