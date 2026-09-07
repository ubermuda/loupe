<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\SeriesRepository;
use App\Security\ProjectScopedSubject;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An ordered set of a project's documents. Scoped to one project, so the same
 * name in two projects is two rows.
 *
 * A tag says that documents belong together. A series also says in which order
 * they are read, which is what the ordinal on Document carries.
 */
#[ORM\Entity(repositoryClass: SeriesRepository::class)]
#[ORM\Table(name: 'series')]
#[ORM\UniqueConstraint(name: 'uniq_series_project_normalized_name', columns: ['project_id', 'normalized_name'])]
class Series implements ProjectScopedSubject
{
    /** Mirrors both name columns' length so callers can reject an over-long name before Postgres does. */
    public const int MAX_NAME_LENGTH = 100;

    /**
     * The largest value the ordinal's `INT` column holds. PHP counts far past
     * it on a 64-bit build, so an ordinal that is merely positive can still
     * overflow the column and fail at flush rather than as a field error.
     */
    public const int MAX_ORDINAL = 2_147_483_647;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * The name as its author wrote it, which is what every screen and every tool
     * result shows. A series name is a title rather than a label, so "Rust
     * Atomics" must read as "Rust Atomics".
     */
    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    public string $name;

    /**
     * The same name folded to a comparison key, carrying the unique constraint
     * on (project_id, normalized_name). It keeps "Rust Atomics" and "rust
     * atomics" one series while the display column keeps the spelling.
     */
    #[ORM\Column(name: 'normalized_name', length: self::MAX_NAME_LENGTH)]
    public string $normalizedName;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,
        string $name,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->name = self::normalizeDisplayName($name);
        $this->normalizedName = self::normalizeName($name);
    }

    /**
     * The stored spelling: the author's own, with the surrounding and interior
     * whitespace tidied. Collapsing interior runs also folds the Unicode spaces
     * `trim()` alone would keep, so a space-only name normalises to nothing
     * rather than becoming a series.
     */
    public static function normalizeDisplayName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * The single definition of what two series names being "the same" means.
     * Case and whitespace are the only differences it folds, so "Rust  Atomics"
     * and "rust atomics" stay one series rather than becoming two.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(self::normalizeDisplayName($name));
    }

    /**
     * Where a document sits: a series name as the caller spelled it and its
     * ordinal, or two nulls when the document belongs to no series.
     *
     * Separate from any write so a caller can find out whether a placement is
     * acceptable before it starts writing. CreateDocumentHandler depends on
     * that: it must reject a bad placement while it can still decline to
     * persist the document.
     *
     * @return array{?string, ?int}
     *
     * @throws DomainErrors if the pair is incomplete, or the name or ordinal is out of range
     */
    public static function normalizePlacement(?string $name, ?int $ordinal): array
    {
        $name = null === $name ? null : self::normalizeDisplayName($name);

        if (null === $name || '' === $name) {
            if (null !== $ordinal) {
                throw new DomainErrors(['series' => 'review.series.error.name_required']);
            }

            return [null, null];
        }

        // Both columns are capped, and folding case can lengthen a string, so
        // the key is measured as well as the spelling.
        if (mb_strlen($name) > self::MAX_NAME_LENGTH || mb_strlen(self::normalizeName($name)) > self::MAX_NAME_LENGTH) {
            throw new DomainErrors(['series' => 'review.series.error.too_long']);
        }

        if (null === $ordinal) {
            throw new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_required']);
        }

        if ($ordinal < 1) {
            throw new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_not_positive']);
        }

        if ($ordinal > self::MAX_ORDINAL) {
            throw new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_too_large']);
        }

        return [$name, $ordinal];
    }

    #[\Override]
    public function scopedProject(): Project
    {
        return $this->project;
    }

    #[\Override]
    public function scopedSubjectType(): string
    {
        return 'series';
    }
}
