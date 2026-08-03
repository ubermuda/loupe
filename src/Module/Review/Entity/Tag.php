<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A label a project's documents can be grouped by. Scoped to one project, so the
 * same name in two projects is two rows.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(name: 'uniq_tag_project_name', columns: ['project_id', 'name'])]
class Tag
{
    /** Mirrors the name column's length so callers can reject an over-long name before Postgres does. */
    public const int MAX_NAME_LENGTH = 50;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * Normalised on the way in, never on the way out, so the unique constraint on
     * (project_id, name) is a plain one and every lookup can compare raw strings.
     */
    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    public readonly string $name;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,
        string $name,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->name = self::normalizeName($name);
    }

    /**
     * The single definition of what two tag names being "the same" means. Callers
     * must run a name through this before looking it up, not only before storing
     * it — a lookup on the raw string misses the stored row and then collides
     * with it on insert.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
