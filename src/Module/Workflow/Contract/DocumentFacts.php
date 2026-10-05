<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class DocumentFacts
{
    /** @param list<string> $tags */
    public function __construct(
        public array $tags,
        public string $status,
        public string $id,
    ) {
    }
}
