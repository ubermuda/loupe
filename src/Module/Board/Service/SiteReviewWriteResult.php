<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** What a site review write did: the cause of its first failure, and whether it changed a row. */
final readonly class SiteReviewWriteResult
{
    public function __construct(
        public ?string $failure,
        public bool $changed,
    ) {
    }
}
