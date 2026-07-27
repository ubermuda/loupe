<?php

declare(strict_types=1);

namespace App\Module\Project\Entity;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\UniqueConstraint(name: 'uniq_project_owner_name', columns: ['owner_id', 'name'])]
class Project
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * The site-review widget token bound to this project. Nullable: a project
     * without one cannot receive widget comments until it is minted. Revoking the
     * token (Account UI) keeps the ApiToken row (see ApiToken::revoke()) and clears
     * this binding explicitly in RevokeApiTokenHandler — the database's ON DELETE
     * SET NULL cascade below only backstops the hard-delete paths (regenerate,
     * project deletion).
     */
    #[ORM\JoinColumn(name: 'widget_token_id', onDelete: 'SET NULL')]
    #[ORM\OneToOne(targetEntity: ApiToken::class)]
    public ?ApiToken $widgetToken = null;

    /**
     * The MCP token bound to this project. The MCP tools resolve their project
     * from this binding — an MCP-scope token without one is rejected.
     */
    #[ORM\JoinColumn(name: 'mcp_token_id', onDelete: 'SET NULL')]
    #[ORM\OneToOne(targetEntity: ApiToken::class)]
    public ?ApiToken $mcpToken = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(length: 100)]
        public string $name,

        #[ORM\Column(length: 255, nullable: true)]
        public ?string $domain = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
