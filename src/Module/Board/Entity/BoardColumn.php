<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One column of a project's board.
 *
 * A seeded column stores a translation key as its label, and every place that
 * shows a label passes it through `trans`, which returns a literal unchanged.
 */
#[ORM\Entity(repositoryClass: BoardColumnRepository::class)]
#[ORM\Table(name: 'board_columns')]
#[ORM\UniqueConstraint(name: 'uniq_board_columns_project_slug', columns: ['project_id', 'slug'])]
class BoardColumn
{
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

        #[ORM\Column(length: 100)]
        public string $label,

        #[ORM\Column(length: 255)]
        public string $slug,

        #[ORM\Column]
        public int $position,

        /** A card that enters a terminal column is complete. */
        #[ORM\Column]
        public bool $terminal = false,

        /** The column a card created with no column lands in. */
        #[ORM\Column]
        public bool $isDefault = false,
    ) {
    }
}
