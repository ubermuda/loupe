<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The workflow template a project runs, stored as the copy it was bound with. */
#[ORM\Entity(repositoryClass: WorkflowBindingRepository::class)]
#[ORM\Table(name: 'workflow_bindings')]
class WorkflowBinding
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: 100)]
        public string $templateKey,

        #[ORM\Column]
        public int $templateVersion,

        /** @var array<mixed> the template array that passed the parser, parsed again on each read */
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $definition,

        #[ORM\Column]
        public \DateTimeImmutable $boundAt = new \DateTimeImmutable(),
    ) {
    }
}
