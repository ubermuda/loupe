<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\ForgePullRequest;

/** A pull request as `owner/repo#n`, the way the verdict panel names it. */
final class PullRequestLabel
{
    public static function of(ForgePullRequest $pullRequest): string
    {
        return \sprintf('%s#%d', $pullRequest->repository, $pullRequest->number);
    }
}
