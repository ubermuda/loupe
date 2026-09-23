<?php

declare(strict_types=1);

namespace App\Module\GitHub\Entity;

use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One installation of the GitHub App, bound to the project it was installed
 * for. A removed installation keeps its row, so a late delivery for it is
 * recognised and dropped.
 */
#[ORM\Entity(repositoryClass: GitHubInstallationRepository::class)]
#[ORM\Table(name: 'github_installations')]
class GitHubInstallation
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $removedAt = null;

    /** True when the connect-time listing stopped at its page cap, so a selection is not fully known. */
    #[ORM\Column(options: ['default' => false])]
    public bool $listIncomplete = false;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(type: Types::BIGINT, unique: true)]
        public readonly int $installationId,

        #[ORM\Column(length: 255)]
        public string $accountLogin,

        #[ORM\Column(length: 20, enumType: GitHubRepositorySelection::class)]
        public GitHubRepositorySelection $repositorySelection,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
