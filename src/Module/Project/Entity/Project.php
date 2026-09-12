<?php

declare(strict_types=1);

namespace App\Module\Project\Entity;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\ProjectScopedSubject;
use App\Utils\Slug;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\UniqueConstraint(name: 'uniq_project_owner_name', columns: ['owner_id', 'name'])]
#[ORM\UniqueConstraint(name: self::SLUG_CONSTRAINT, columns: ['owner_id', 'slug'])]
class Project implements ProjectScopedSubject
{
    public const string SLUG_CONSTRAINT = 'uniq_project_owner_slug';

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

    /**
     * The stemming language a new document in this project gets when the caller
     * names none. Read once, at creation: each document then carries its own
     * language, so changing this leaves the documents already written alone.
     */
    #[ORM\Column(name: 'search_language', length: 20, enumType: SearchLanguage::class, options: ['default' => SearchLanguage::DEFAULT->value])]
    public SearchLanguage $searchLanguage = SearchLanguage::DEFAULT;

    /**
     * The handle a rule file names the project by, rewritten from the name on every
     * write to it (see Slug::forName()). Null only on a row an image older than the
     * column wrote.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $slug = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(length: 100)]
        public string $name {
            set(string $name) {
                $this->slug = Slug::forName($name, $this->slug);
                $this->name = $name;
            }
        },

        #[ORM\Column(length: 255, nullable: true)]
        public ?string $domain = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    #[\Override]
    public function scopedProject(): Project
    {
        return $this;
    }

    #[\Override]
    public function scopedSubjectType(): string
    {
        return 'project';
    }
}
