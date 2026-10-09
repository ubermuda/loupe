<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** What Loupe last posted as the site review check of one pull request. */
#[ORM\Entity(repositoryClass: SiteReviewCheckStateRepository::class)]
#[ORM\Table(name: 'board_site_review_check_states')]
class SiteReviewCheckState
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: ForgePullRequest::class)]
        public readonly ForgePullRequest $pullRequest,

        #[ORM\Column(length: 64)]
        public string $headSha,

        /** The conclusion as the forge names it, such as `success` or `failure`. */
        #[ORM\Column(length: 20)]
        public string $conclusion,

        /** How many notes the posted summary counted. */
        #[ORM\Column]
        public int $noteCount,

        /** The forge's id of the check run, kept to update the run of the same head. */
        #[ORM\Column(type: Types::BIGINT, nullable: true)]
        public ?int $checkRunId = null,

        /** A digest of the notes the posted summary listed. */
        #[ORM\Column(length: 64, nullable: true)]
        public ?string $notesDigest = null,

        #[ORM\Column]
        public \DateTimeImmutable $postedAt = new \DateTimeImmutable(),
    ) {
    }
}
