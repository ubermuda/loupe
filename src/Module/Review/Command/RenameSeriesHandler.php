<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Series;
use App\Module\Review\Repository\SeriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Renames a series in place, so every document already in it keeps its place.
 *
 * A name the project already uses is refused rather than merged. Two series
 * hold two independent numberings, and merging them would put two documents on
 * the same ordinal with no way to say which one moves.
 */
final readonly class RenameSeriesHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private SeriesRepository $series,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RenameSeriesCommand $command): Series
    {
        $series = $command->series;
        $name = Series::normalizeName($command->newName);

        if ('' === $name) {
            throw new DomainErrors(['series' => 'review.series.error.name_required']);
        }

        if (mb_strlen($name) > Series::MAX_NAME_LENGTH) {
            throw new DomainErrors(['series' => 'review.series.error.too_long']);
        }

        $existing = $this->series->findOneByProjectAndName($series->project, $name);
        if (null !== $existing && $existing !== $series) {
            throw new DomainErrors(['series' => 'review.series.error.name_taken']);
        }

        $series->name = $name;
        $this->em->flush();

        $this->auditor->record(
            'review.series_renamed',
            AuditOutcome::Success,
            [
                'seriesId' => (string) $series->id,
                'projectId' => (string) $series->project->id,
            ],
            new AuditSubject('series', (string) $series->id),
        );

        return $series;
    }
}
