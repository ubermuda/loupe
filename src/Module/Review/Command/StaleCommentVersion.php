<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

/**
 * Thrown when a reply targets a comment that belongs to a superseded version of
 * the document. Revising copies open threads onto the new version and leaves the
 * originals, so a pre-revision id still resolves, and a reply on it lands on a
 * version nothing reads.
 *
 * It carries both version numbers, because the only useful answer names them.
 */
final class StaleCommentVersion extends \DomainException
{
    public function __construct(
        public readonly int $commentVersionNumber,
        public readonly int $currentVersionNumber,
    ) {
        parent::__construct(\sprintf('Comment belongs to version %d, but the document is now on version %d.', $commentVersionNumber, $currentVersionNumber));
    }
}
