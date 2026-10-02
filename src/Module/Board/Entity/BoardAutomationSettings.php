<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** How the board drives the pull requests of the cards of one project, and how much finished work it shows. */
#[ORM\Entity(repositoryClass: BoardAutomationSettingsRepository::class)]
#[ORM\Table(name: 'board_automation_settings')]
class BoardAutomationSettings
{
    public const int MIN_LOOP_LIMIT = 1;

    public const int MAX_LOOP_LIMIT = 20;

    public const int MIN_TERMINAL_WINDOW_DAYS = 1;

    public const int MAX_TERMINAL_WINDOW_DAYS = 30;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column]
        public bool $enabled = true,

        #[ORM\Column(length: 20, enumType: BoardMergeStrategy::class)]
        public BoardMergeStrategy $mergeStrategy = BoardMergeStrategy::Worker,

        #[ORM\Column(length: 20, enumType: BoardFixStrategy::class)]
        public BoardFixStrategy $fixStrategy = BoardFixStrategy::Fresh,

        /** The automatic fix rounds a card gets before the board stops asking. */
        #[ORM\Column]
        public int $loopLimit = 3,

        /** Posts a comment on the pull request when a fix run is queued for it. */
        #[ORM\Column(options: ['default' => false])]
        public bool $commentOnFixQueued = false,

        /** Posts a comment on the pull request when its head moves past the approval. */
        #[ORM\Column(options: ['default' => false])]
        public bool $commentOnStaleApproval = false,

        /** Brings an approved pull request that is behind its base up to date. */
        #[ORM\Column(options: ['default' => false])]
        public bool $syncBehind = false,

        /** How many days back a terminal column of the board reads. The history page shows the rest. */
        #[ORM\Column(options: ['default' => 3])]
        public int $terminalWindowDays = 3,
    ) {
    }
}
