<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class SetDocumentSeriesCommand
{
    /**
     * @param ?string $seriesName raw name as typed; normalisation and implicit
     *                            creation are the handler's job. Null takes the
     *                            document out of the series it was in
     */
    public function __construct(
        public Document $document,
        public ?string $seriesName,
        public ?int $seriesOrdinal,
    ) {
    }
}
