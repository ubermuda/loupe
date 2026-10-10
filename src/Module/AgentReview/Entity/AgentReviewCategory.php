<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

enum AgentReviewCategory: string
{
    case Code = 'code';
    case Spec = 'spec';
}
