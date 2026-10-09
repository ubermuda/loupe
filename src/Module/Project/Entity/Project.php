<?php

declare(strict_types=1);

namespace App\Module\Project\Entity;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\ProjectScopedSubject;
use App\Utils\GitHubLogin;
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
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_DOMAIN_LENGTH = 255;
    public const int MAX_DESCRIPTION_LENGTH = 500;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function requireId(): Uuid
    {
        return $this->id ?? throw new \LogicException('A stored project has an id.');
    }

    /**
     * Whether a site-review submission may reach the owner's agent. Off by
     * default, because a reviewer who can open the page can then reach the
     * owner's agent.
     */
    #[ORM\Column(options: ['default' => false])]
    public bool $forwardsToAgent = false;

    /**
     * The sites the sign-in widget may run on, each in SiteOrigins form. The
     * OAuth callback posts a code only to an origin on this list.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'allowed_origins', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $allowedOrigins = [];

    /**
     * The stemming language a new document in this project gets when the caller
     * names none. Read once, at creation: each document then carries its own
     * language, so changing this leaves the documents already written alone.
     */
    #[ORM\Column(name: 'search_language', length: 20, enumType: SearchLanguage::class, options: ['default' => SearchLanguage::DEFAULT->value])]
    public SearchLanguage $searchLanguage = SearchLanguage::DEFAULT;

    /**
     * The handle a rule file names the project by. Every write to the name derives
     * it (see Slug::forName()). Null only on a row an image older than the column wrote.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $slug = null;

    /** Set when the owner hides the readiness guide, or by the backfill for a board already in use. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $readinessGuideHiddenAt = null;

    /** The first time an MCP request resolved this project. ProjectRepository::markAgentSeen() writes it. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $agentFirstSeenAt = null;

    /** The GitHub user the agents of this project push as. The owner records it on the agent account page. */
    #[ORM\Column(name: 'agent_github_login', length: GitHubLogin::MAX_LENGTH, nullable: true)]
    public ?string $agentGitHubLogin = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(length: self::MAX_NAME_LENGTH)]
        public string $name {
            set(string $name) {
                $this->slug = Slug::forName($name, $this->slug);
                $this->name = $name;
            }
        },

        #[ORM\Column(length: self::MAX_DOMAIN_LENGTH, nullable: true)]
        public ?string $domain = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public ?string $description = null,
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
