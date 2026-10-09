<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

enum AgentReviewSeverity: string
{
    case Important = 'important';
    case Nit = 'nit';
    case PreExisting = 'pre-existing';
}
