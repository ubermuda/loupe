<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

/** One check of the readiness guide. Every string is a translation key, except a detail with no key of its own and the status parameters. */
final readonly class WorkshopReadinessRow
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $done,
        public string $status,
        public ?string $detail = null,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        /** @var array<string, string> */
        public array $statusParameters = [],
    ) {
    }
}
