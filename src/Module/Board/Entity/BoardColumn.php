<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\LabelTone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One column of a project's board. A seeded column stores a translation key as
 * its label.
 */
#[ORM\Entity(repositoryClass: BoardColumnRepository::class)]
#[ORM\Table(name: 'board_columns')]
#[ORM\UniqueConstraint(name: 'uniq_board_columns_project_slug', columns: ['project_id', 'slug'])]
class BoardColumn
{
    public const int MAX_LABEL_LENGTH = 100;
    public const string BACKLOG_SLUG = 'backlog';

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

        #[ORM\Column(length: self::MAX_LABEL_LENGTH)]
        public string $label,

        /** Text, because a slug derived from a 100-character label can reach 1,700 characters. */
        #[ORM\Column(type: Types::TEXT)]
        public string $slug,

        #[ORM\Column]
        public int $position,

        /** A card that enters a terminal column is complete. */
        #[ORM\Column]
        public bool $terminal = false,

        /**
         * The Backlog, where a card created with no column lands. The board does
         * not draw it. The column keeps its old name, because a rename would break
         * the previous image during a deploy.
         */
        #[ORM\Column(name: 'is_default')]
        public bool $backlog = false,

        /** The column's label colour. The database default lets older migrations insert rows without it. */
        #[ORM\Column(length: 20, enumType: LabelTone::class, options: ['default' => 'neutral'])]
        public LabelTone $tone = LabelTone::Neutral,
    ) {
    }

    public function ref(): ColumnRef
    {
        return new ColumnRef($this->id ?? throw new \LogicException('A stored column has an id.'), $this->backlog, $this->terminal);
    }
}
