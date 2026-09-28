<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

use Symfony\Component\Uid\Uuid;

/**
 * The status or the archive state of a document may have changed. Dispatched
 * after the commit, with ids only. A verdict dispatches ReviewSubmitted instead.
 */
final readonly class DocumentStatusChanged
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $documentId,
    ) {
    }
}
