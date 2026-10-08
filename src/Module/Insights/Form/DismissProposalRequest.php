<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Insights\Entity\Proposal;
use Symfony\Component\Validator\Constraints as Assert;

class DismissProposalRequest
{
    public function __construct(
        #[Assert\Length(max: Proposal::MAX_DISMISS_REASON_LENGTH)]
        public ?string $reason = null,
    ) {
    }
}
