<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A comment the board posts on a pull request once per key, such as a stale approval of one head. */
#[ORM\Entity(repositoryClass: PullRequestNoticeRepository::class)]
#[ORM\Table(name: 'board_pull_request_notices')]
#[ORM\UniqueConstraint(name: 'uniq_board_pull_request_notices_pull_request_key', columns: ['project_id', 'forge', 'repository', 'number', 'notice_key'])]
class PullRequestNotice
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

        /** The Forge row of the pull request. Forge keeps it through a rename. */
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $forgePullRequestId,

        /** The forge's slug, such as `github`. */
        #[ORM\Column(length: 50)]
        public string $forge,

        /** Keyed with the number, because a pull request linked again gets a new Forge row. */
        #[ORM\Column(length: 255)]
        public string $repository,

        #[ORM\Column]
        public int $number,

        #[ORM\Column(length: 100)]
        public string $noticeKey,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
