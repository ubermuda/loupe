<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

use Symfony\Component\Uid\Uuid;

/** The title of a document changed. Dispatched after the flush, with ids only. */
final readonly class DocumentRenamed
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $documentId,
    ) {
    }
}
