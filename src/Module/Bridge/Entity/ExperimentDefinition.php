<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The latest weights the bridge reported for the variants of one experiment. */
#[ORM\Entity(repositoryClass: ExperimentDefinitionRepository::class)]
#[ORM\Table(name: 'bridge_experiment_definitions')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_experiment_definition', columns: ['project_id', 'experiment'])]
class ExperimentDefinition
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

        #[ORM\Column(name: 'experiment', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH)]
        public string $experiment,

        /** @var array<string, int> the weight of each variant, by name */
        #[ORM\Column(name: 'weights', type: Types::JSON)]
        public array $weights,

        #[ORM\Column(name: 'reported_at')]
        public \DateTimeImmutable $reportedAt = new \DateTimeImmutable(),
    ) {
    }
}
