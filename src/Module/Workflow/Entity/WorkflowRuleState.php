<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** What the engine remembers about one rule of the template for one card. */
#[ORM\Entity(repositoryClass: WorkflowRuleStateRepository::class)]
#[ORM\Index(name: 'idx_workflow_rule_states_due_at', columns: ['due_at'])]
#[ORM\Table(name: 'workflow_rule_states')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_rule_states_card_rule', columns: ['card_id', 'rule_id'])]
class WorkflowRuleState
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $truth = false;

    #[ORM\Column(options: ['default' => 0])]
    public int $attempts = 0;

    /** The requests the rule fired while it applied to the card. */
    #[ORM\Column(options: ['default' => 0])]
    public int $fires = 0;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $fingerprint = null;

    /** The time a retry of the rule is due. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $lastRefusal = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastRefusalAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: 100)]
        public readonly string $ruleId,

        #[ORM\Column]
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }

    /** Forgets the rule, for a card whose slot the rule does not apply to. The fingerprint stays. */
    public function reset(): void
    {
        $this->truth = false;
        $this->attempts = 0;
        $this->fires = 0;
        $this->dueAt = null;
        $this->lastRefusal = null;
        $this->lastRefusalAt = null;
    }
}
