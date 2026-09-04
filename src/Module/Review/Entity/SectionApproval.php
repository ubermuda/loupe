<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Review\Repository\SectionApprovalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One reviewer's approval of one section of a document.
 *
 * Keyed to the DOCUMENT and the heading's own id, like DecisionSelection, so the
 * record outlives the version it was made on. The content hash is the second
 * half of the key: it says WHICH text was approved, so a revision that rewrites
 * the section drops the approval instead of carrying a claim about text nobody
 * read. A revision that leaves the section alone keeps it.
 */
#[ORM\Entity(repositoryClass: SectionApprovalRepository::class)]
#[ORM\Table(name: 'section_approvals')]
#[ORM\UniqueConstraint(name: 'uniq_section_approval_heading', columns: ['document_id', 'heading_id', 'approver_id'])]
class SectionApproval
{
    /**
     * Bounds the heading id so the unique index above stays inside Postgres's
     * btree entry limit. A heading whose slug is longer cannot be approved.
     */
    public const int MAX_HEADING_ID_LENGTH = 255;

    /** A sha256 digest in lower-case hexadecimal. */
    public const int CONTENT_HASH_LENGTH = 64;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Document::class)]
        public readonly Document $document,

        #[ORM\Column(length: self::MAX_HEADING_ID_LENGTH)]
        public readonly string $headingId,

        /** The section's plain text as it read when the reviewer approved it. */
        #[ORM\Column(length: self::CONTENT_HASH_LENGTH)]
        public string $contentHash,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $approver,

        /** Which version the reviewer was reading. The approval outlives it. */
        #[ORM\Column]
        public int $versionNumber,

        #[ORM\Column]
        public \DateTimeImmutable $approvedAt = new \DateTimeImmutable(),
    ) {
    }
}
