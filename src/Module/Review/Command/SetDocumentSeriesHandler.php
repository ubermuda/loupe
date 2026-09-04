<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;
use App\Module\Review\Service\DocumentSeriesApplier;
use App\Module\Review\Service\SeriesConflictErrors;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Moves a document to one place in one series, or takes it out of the series
 * it was in.
 *
 * DocumentSeriesApplier holds the applying, so a caller that places a document
 * as part of a larger operation records that operation instead of this one.
 */
final readonly class SetDocumentSeriesHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentSeriesApplier $seriesApplier,
        private SeriesConflictErrors $conflicts,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SetDocumentSeriesCommand $command): ?Series
    {
        $document = $command->document;
        $series = $this->seriesApplier->apply($document, $command->seriesName, $command->seriesOrdinal);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw $this->conflicts->forViolation($e) ?? $e;
        }

        // An ordinal, not a name: a series name is a phrase a person typed.
        $this->auditor->record(
            'review.document_series_updated',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'projectId' => (string) $document->project->id,
                'inSeries' => null !== $series,
                'seriesOrdinal' => $document->seriesOrdinal,
            ],
            new AuditSubject('document', (string) $document->id),
        );

        return $series;
    }
}
