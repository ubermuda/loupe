<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One forge repository and the one project that owns it. The unique key is
 * the forge's own stable id, so a rename or a transfer keeps the owner.
 */
#[ORM\Entity(repositoryClass: ForgeRepositoryRepository::class)]
#[ORM\Table(name: 'forge_repositories')]
#[ORM\UniqueConstraint(name: self::OWNER_CONSTRAINT, columns: ['forge', 'external_id'])]
class ForgeRepository
{
    public const string OWNER_CONSTRAINT = 'uniq_forge_repositories_forge_external_id';

    private const string QUIET_AFTER = '-30 days';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastAcceptedAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** The forge's slug, such as `github`. */
        #[ORM\Column(length: 50)]
        public readonly string $forge,

        #[ORM\Column(length: 255)]
        public readonly string $externalId,

        /** The current path as the forge sent it. GitLab nests groups, so it may hold more than one slash. */
        #[ORM\Column(length: 255)]
        public string $path,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function health(\DateTimeImmutable $now): ForgeRepositoryHealth
    {
        if (null === $this->lastAcceptedAt) {
            return ForgeRepositoryHealth::Waiting;
        }

        return $this->lastAcceptedAt < $now->modify(self::QUIET_AFTER) ? ForgeRepositoryHealth::Quiet : ForgeRepositoryHealth::Working;
    }
}
