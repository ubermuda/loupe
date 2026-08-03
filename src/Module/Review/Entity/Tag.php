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
     *
     * Not promoted: the constructor rewrites the argument, and a promoted readonly
     * property cannot be reassigned in the body. Promotion would mean
     * `public private(set)`, which this class keeps for `$id` alone — the one
     * property Doctrine must write after the constructor has run. `readonly` is
     * also the stronger guarantee: nothing can later store a name that the key
     * used to find it no longer matches.
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
     * The single definition of what two tag names being "the same" means.
     *
     * Interior runs of whitespace collapse as well as leading and trailing ones,
     * or "design  spec" and "design spec" become two rows — the near-duplicate
     * problem the tools warn agents about, arrived at by a typo instead of a
     * choice. Collapsing also folds Unicode spaces `trim()` alone would keep, so
     * a non-breaking-space-only name normalises to nothing rather than becoming a
     * tag. Punctuation-only names are deliberately allowed: "c++" and "v2" are
     * names somebody means.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
