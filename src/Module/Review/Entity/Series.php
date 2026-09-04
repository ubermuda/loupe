<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\SeriesRepository;
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
#[ORM\UniqueConstraint(name: 'uniq_series_project_name', columns: ['project_id', 'name'])]
class Series
{
    /** Mirrors the name column's length so callers can reject an over-long name before Postgres does. */
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * Normalised on the way in, never on the way out, so the unique constraint
     * on (project_id, name) is a plain one and every lookup compares raw
     * strings. A rename goes through RenameSeriesHandler, which normalises the
     * new name the same way, so nothing can store a name a lookup would miss.
     */
    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    public string $name;

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
     * The single definition of what two series names being "the same" means.
     *
     * The same rule as a tag name: interior runs of whitespace collapse as well
     * as the leading and trailing ones, so "blog  series" and "blog series" stay
     * one series rather than becoming two.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Where a document sits: a normalised series name and its ordinal, or two
     * nulls when the document belongs to no series.
     *
     * Separate from any write so a caller can find out whether a placement is
     * acceptable before it starts writing. CreateDocumentHandler depends on
     * that: it must reject a bad placement while it can still decline to persist
     * the document.
     *
     * @return array{?string, ?int}
     *
     * @throws DomainErrors if the pair is incomplete, or the name or ordinal is out of range
     */
    public static function normalizePlacement(?string $name, ?int $ordinal): array
    {
        $name = null === $name ? null : self::normalizeName($name);

        if (null === $name || '' === $name) {
            if (null !== $ordinal) {
                throw new DomainErrors(['series' => 'review.series.error.name_required']);
            }

            return [null, null];
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new DomainErrors(['series' => 'review.series.error.too_long']);
        }

        if (null === $ordinal) {
            throw new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_required']);
        }

        if ($ordinal < 1) {
            throw new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_not_positive']);
        }

        return [$name, $ordinal];
    }
}
