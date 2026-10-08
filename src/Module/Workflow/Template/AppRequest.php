<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** Work the app asks a bridge for outside the engine, with the prompt file that ships with it. */
final readonly class AppRequest
{
    /** @param list<string> $checks */
    public function __construct(
        public string $id,
        public string $kind,
        public string $prompt,
        public array $checks,
    ) {
    }
}
