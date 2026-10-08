<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

enum ProposalKind: string
{
    case Card = 'card';
    case BucketRule = 'bucket-rule';
}
