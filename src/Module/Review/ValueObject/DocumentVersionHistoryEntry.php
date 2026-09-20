<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

use App\Module\Review\Entity\Review;

final readonly class DocumentVersionHistoryEntry
{
    /** @param list<Review> $reviews */
    public function __construct(
        public int $versionNumber,
        public \DateTimeImmutable $createdAt,
        public ?string $description,
        public array $reviews,
        public int $threadCount = 0,
    ) {
    }
}
