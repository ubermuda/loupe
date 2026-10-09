<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

enum PullRequestCheckConclusion: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Neutral = 'neutral';
}
