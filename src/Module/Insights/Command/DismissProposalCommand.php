<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\Proposal;

final readonly class DismissProposalCommand
{
    public function __construct(
        public Proposal $proposal,
        public ?string $reason = null,
    ) {
    }
}
