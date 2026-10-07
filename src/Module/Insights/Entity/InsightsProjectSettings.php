<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The analysis settings of a project. A null model or effort reads the instance flag. */
#[ORM\Entity(repositoryClass: InsightsProjectSettingsRepository::class)]
#[ORM\Table(name: 'insights_project_settings')]
class InsightsProjectSettings
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(name: 'default_model', length: 64, nullable: true)]
    public ?string $defaultModel = null;

    #[ORM\Column(name: 'default_effort', length: 16, nullable: true)]
    public ?string $defaultEffort = null;

    /** Whether the bridge sends the full text of each tool call. */
    #[ORM\Column(name: 'collect_full_text', options: ['default' => false])]
    public bool $collectFullText = false;

    /**
     * The programs whose second word joins the signature of a shell command. Null keeps the instance list.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'subcommand_programs', type: Types::JSON, nullable: true)]
    public ?array $subcommandPrograms = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: Project::class)]
        public readonly Project $project,
    ) {
    }
}
