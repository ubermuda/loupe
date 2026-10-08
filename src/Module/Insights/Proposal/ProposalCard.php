<?php

declare(strict_types=1);

namespace App\Module\Insights\Proposal;

use Symfony\Component\Uid\Uuid;

/** The backlog card an accepted proposal becomes. */
final readonly class ProposalCard
{
    public function __construct(
        public string $title,
        public string $body,
        /** The report of the analysis, linked to the card. */
        public ?Uuid $reportDocumentId,
    ) {
    }
}
