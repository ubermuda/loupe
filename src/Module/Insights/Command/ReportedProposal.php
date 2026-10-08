<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

/** One proposal as the agent reports it. The handler checks every field. */
final readonly class ReportedProposal
{
    /** @param array<mixed>|null $payload */
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?array $payload = null,
        public ?string $estimatedSaving = null,
    ) {
    }
}
