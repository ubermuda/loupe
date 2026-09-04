<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Exception\DomainErrors;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Reads a series unique-index violation back as the domain error the caller
 * already handles.
 *
 * Both series conflicts are checked before the write, and both checks are
 * reads: two concurrent requests can pass the same check and the index settles
 * which one lands. Without this the loser reads as an internal failure rather
 * than the refusal it is.
 *
 * The failed flush closes the EntityManager, so the caller must end the request
 * on this error rather than carry on writing.
 */
final readonly class SeriesConflictErrors
{
    /** Null for a violation of any other index, which the caller re-throws. */
    public function forViolation(UniqueConstraintViolationException $violation): ?DomainErrors
    {
        $message = $violation->getMessage();

        if (str_contains($message, 'uniq_document_series_ordinal')) {
            return new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_taken']);
        }

        if (str_contains($message, 'uniq_series_project_name')) {
            return new DomainErrors(['series' => 'review.series.error.name_taken']);
        }

        return null;
    }
}
