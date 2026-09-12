<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\SeriesRepository;

final readonly class ListSeriesHandler
{
    public function __construct(
        private SeriesRepository $series,
    ) {
    }

    public function __invoke(ListSeriesCommand $command): ListSeriesView
    {
        return new ListSeriesView($this->series->findByProjectWithDocumentCounts($command->project));
    }
}
