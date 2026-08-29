<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * One act of engagement with a document: when it happened, and the version the
 * reader was looking at when it did.
 */
final readonly class Watermark
{
    public function __construct(
        public \DateTimeImmutable $at,
        public int $versionNumber,
    ) {
    }
}
