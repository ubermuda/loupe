<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Review\Repository\DecisionAnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The shared answer to one decision block: who saved it last, when, and the
 * note that goes with the chosen options. The options stay in DecisionSelection.
 */
#[ORM\Entity(repositoryClass: DecisionAnswerRepository::class)]
#[ORM\Table(name: 'decision_answers')]
#[ORM\UniqueConstraint(name: 'uniq_decision_answer', columns: ['document_id', 'decision_id'])]
class DecisionAnswer
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Document::class)]
        public readonly Document $document,

        #[ORM\Column(length: DecisionSelection::MAX_DECISION_ID_LENGTH)]
        public readonly string $decisionId,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public ?string $note,

        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $answeredBy,

        #[ORM\Column]
        public int $answeredAtVersion,

        #[ORM\Column]
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }
}
