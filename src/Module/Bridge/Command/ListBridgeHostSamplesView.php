<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\BridgeHostSampleReport;

final readonly class ListBridgeHostSamplesView
{
    /** @param list<BridgeHostSampleReport> $samples */
    public function __construct(
        public array $samples,
        public int $page,
        public int $perPage,
        public int $total,
        public ?string $note = null,
    ) {
    }
}
