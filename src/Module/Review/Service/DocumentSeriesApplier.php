<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\SeriesRepository;

/**
 * Puts a document at one place in one series, creating the series if the
 * project does not have it yet. A null name takes the document out of whatever
 * series it was in.
 *
 * It records nothing and it does not flush, so each caller writes the one
 * operation it performed.
 */
final readonly class DocumentSeriesApplier
{
    public function __construct(
        private SeriesRepository $series,
        private DocumentRepository $documents,
    ) {
    }

    /**
     * @param ?string $seriesName raw name as typed; null or blank clears the placement
     *
     * @return ?Series the series the document is now in, or null
     *
     * @throws DomainErrors if the placement is incomplete, out of range, or already taken
     */
    public function apply(Document $document, ?string $seriesName, ?int $ordinal): ?Series
    {
        // Throws before anything below is touched, so a rejected placement
        // leaves the document exactly as it was.
        [$name, $ordinal] = Series::normalizePlacement($seriesName, $ordinal);

        if (null === $name || null === $ordinal) {
            $document->series = null;
            $document->seriesOrdinal = null;

            return null;
        }

        $series = $this->series->findOrCreate($document->project, $name);

        // The database rejects a duplicate ordinal too, but a constraint
        // violation closes the EntityManager and reads to the caller as a
        // generic failure. Identity, not id: on a create the document has no id
        // yet, and re-sending the ordinal it already holds is not a clash.
        $holder = $this->documents->findOneInSeriesAt($series, $ordinal);
        if (null !== $holder && $holder !== $document) {
            throw new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_taken']);
        }

        $document->series = $series;
        $document->seriesOrdinal = $ordinal;

        return $series;
    }
}
