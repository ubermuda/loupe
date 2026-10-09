<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The board column that holds one slot of the project's workflow template. */
#[ORM\Entity(repositoryClass: WorkflowSlotLinkRepository::class)]
#[ORM\Table(name: 'workflow_slot_links')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_slot_links_project_slot', columns: ['project_id', 'slot_key'])]
class WorkflowSlotLink
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: 100)]
        public string $slotKey,

        /** Null once the column is deleted, so the slot stays and shows as unlinked. No foreign key; WorkflowRowCleanup clears it. */
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public ?Uuid $columnId,
    ) {
    }
}
