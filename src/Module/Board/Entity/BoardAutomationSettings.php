<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Whether the workflow of a project's board runs, and how much finished work the board shows. */
#[ORM\Entity(repositoryClass: BoardAutomationSettingsRepository::class)]
#[ORM\Table(name: 'board_automation_settings')]
class BoardAutomationSettings
{
    public const int MIN_TERMINAL_WINDOW_DAYS = 1;

    public const int MAX_TERMINAL_WINDOW_DAYS = 30;

    public const int MIN_STUCK_DELAY_MINUTES = 1;

    public const int MAX_STUCK_DELAY_MINUTES = 1440;

    public const int DEFAULT_STUCK_DELAY_MINUTES = 15;

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

        /** How many days back a terminal column of the board reads. The history page shows the rest. */
        #[ORM\Column(options: ['default' => 3])]
        public int $terminalWindowDays = 3,

        /** How long a ready pull request may wait for a merge before its card shows Stuck. */
        #[ORM\Column(options: ['default' => self::DEFAULT_STUCK_DELAY_MINUTES])]
        public int $stuckDelayMinutes = self::DEFAULT_STUCK_DELAY_MINUTES,
    ) {
    }
}
