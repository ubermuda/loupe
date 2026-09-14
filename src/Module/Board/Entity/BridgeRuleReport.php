<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The rule health one bridge last reported for one project. The same bridge
 * replaces it, and nothing else removes it while the project lives.
 */
#[ORM\Entity(repositoryClass: BridgeRuleReportRepository::class)]
#[ORM\Table(name: 'board_bridge_rule_reports')]
#[ORM\UniqueConstraint(name: 'uniq_board_bridge_rule_reports_project_bridge', columns: ['project_id', 'bridge_id'])]
class BridgeRuleReport
{
    public const string STATE_LIVE = 'live';
    public const string STATE_DEAD = 'dead';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        /** Cascades, so an image that predates this table can still delete a project. */
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $bridgeId,

        /** @var list<array{name: string, on: string, columns: list<string>, state: string, reason: ?string}> */
        #[ORM\Column(type: Types::JSON)]
        public array $rules,

        #[ORM\Column]
        public \DateTimeImmutable $receivedAt = new \DateTimeImmutable(),
    ) {
    }
}
