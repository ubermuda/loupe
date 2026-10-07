<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

/** One card that a discovery report proposes, as the worker states it. */
final readonly class ReportProposal
{
    public function __construct(
        public string $key,
        public string $title,
        /** A card type value. The handler refuses a type it does not allow. */
        public string $type,
        public string $body,
        /** The number of an open card that covers the proposal already. */
        public ?int $openCardNumber = null,
    ) {
    }
}
