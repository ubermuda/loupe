<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

enum PullRequestCheckAnnotationLevel: string
{
    case Notice = 'notice';
    case Warning = 'warning';
    case Failure = 'failure';
}
