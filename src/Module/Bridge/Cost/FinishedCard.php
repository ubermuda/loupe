<?php

declare(strict_types=1);

namespace App\Module\Bridge\Cost;

use Symfony\Component\Uid\Uuid;

/** A card that sits in a terminal column of its board. */
final readonly class FinishedCard
{
    public function __construct(
        public Uuid $id,
        public int $number,
        public string $title,
        public \DateTimeImmutable $completedAt,
    ) {
    }
}
