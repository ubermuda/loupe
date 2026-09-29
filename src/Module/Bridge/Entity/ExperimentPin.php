<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The variant of one experiment that one card runs with. The first run of the
 * card picks it, and later runs keep it while the rule still offers it.
 */
#[ORM\Entity(repositoryClass: ExperimentPinRepository::class)]
// The retention sweep deletes the pins that no run has refreshed.
#[ORM\Index(name: 'idx_bridge_experiment_pins_updated', columns: ['updated_at'])]
#[ORM\Table(name: 'bridge_experiment_pins')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_experiment_pin', columns: ['project_id', 'card_id', 'experiment'])]
class ExperimentPin
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        /** A scalar, never a foreign key, like the card of a run. */
        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public Uuid $cardId,

        #[ORM\Column(name: 'experiment', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH)]
        public string $experiment,

        #[ORM\Column(name: 'variant', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH)]
        public string $variant,

        #[ORM\Column(name: 'created_at')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),

        #[ORM\Column(name: 'updated_at')]
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }
}
