<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

enum BucketRuleDirection: string
{
    case Up = 'up';
    case Down = 'down';
}
