<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

enum ProposalState: string
{
    case Proposed = 'proposed';
    case Created = 'created';
    case Dismissed = 'dismissed';
}
