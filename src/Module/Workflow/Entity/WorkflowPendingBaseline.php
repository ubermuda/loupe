<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The board automation was off, so the first evaluation of the card after it is on records the truth of its rules and fires none. */
#[ORM\Entity(repositoryClass: WorkflowPendingBaselineRepository::class)]
#[ORM\Table(name: 'workflow_pending_baselines')]
class WorkflowPendingBaseline
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        /** No foreign key, as the card may go. A Board listener deletes the row through WorkflowRowCleanup. */
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        public readonly Uuid $cardId,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
