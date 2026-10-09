<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

interface ReadsDocumentTag
{
    /**
     * The document tag the condition reads.
     *
     * @param array<string, mixed> $params
     */
    public function documentTag(array $params): ?string;
}
