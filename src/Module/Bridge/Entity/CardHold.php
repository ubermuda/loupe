<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A person stopped the work on this card. No bridge starts a worker on it until
 * a person moves the card to another column or resumes the run.
 */
#[ORM\Entity(repositoryClass: CardHoldRepository::class)]
#[ORM\Table(name: 'bridge_card_holds')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_card_hold', columns: ['project_id', 'card_id'])]
class CardHold
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** A scalar, never a foreign key, like the card of a run. */
        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public readonly Uuid $cardId,

        // The retention sweep deletes runs with DQL, so only the database can unlink the hold.
        #[ORM\JoinColumn(name: 'stopped_run_id', nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: WorkerRun::class)]
        public ?WorkerRun $stoppedRun,

        #[ORM\JoinColumn(name: 'held_by_id', nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $heldBy,

        #[ORM\Column(name: 'held_at')]
        public \DateTimeImmutable $heldAt,
    ) {
    }
}
