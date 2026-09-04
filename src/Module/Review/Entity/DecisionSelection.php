<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Review\Repository\DecisionSelectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A reviewer's answer to one option of one decision block.
 *
 * Keyed to the DOCUMENT and the decision's own identifier, never to a version
 * or to the quoted text: a revision that rewords a decision block is exactly
 * what a revision responding to feedback about that decision does, and keying
 * on the text would discard the answer at the moment it is being acted upon.
 * The trade is that changing a published id silently drops its answer.
 *
 * A multi-choice block stores one row per chosen option, so the option index
 * belongs to the unique key. A single-choice block keeps at most one row, and
 * the handler is what holds it to that.
 */
#[ORM\Entity(repositoryClass: DecisionSelectionRepository::class)]
#[ORM\Table(name: 'decision_selections')]
#[ORM\UniqueConstraint(name: 'uniq_decision_selection_option', columns: ['document_id', 'decision_id', 'option_index'])]
class DecisionSelection
{
    /** Mirrors the id pattern DecisionBlockService accepts in a fence. */
    public const int MAX_DECISION_ID_LENGTH = 64;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Document::class)]
        public readonly Document $document,

        #[ORM\Column(length: self::MAX_DECISION_ID_LENGTH)]
        public readonly string $decisionId,

        #[ORM\Column]
        public int $optionIndex,

        /**
         * The option as it read when it was chosen. Reported to the agent instead
         * of whatever now sits at that index, so the record says what the reviewer
         * actually agreed to even after the block is reworded or reordered.
         */
        #[ORM\Column(type: Types::TEXT)]
        public string $optionLabel,

        /** Which version the reviewer was reading — the answer outlives it. */
        #[ORM\Column]
        public int $versionNumber,

        #[ORM\Column]
        public \DateTimeImmutable $selectedAt = new \DateTimeImmutable(),
    ) {
    }
}
