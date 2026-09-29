<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;

/** Which reads of a pull request may move a card that links it. */
final readonly class PullRequestMoveTriggers
{
    public static function finished(PullRequestSnapshot $previous, PullRequestSnapshot $current): bool
    {
        return $previous->state !== $current->state && PullRequestState::Open !== $current->state;
    }

    /** A draft marked ready keeps the checks it passed as a draft, so the ready change counts too. */
    public static function green(PullRequestSnapshot $previous, PullRequestSnapshot $current): bool
    {
        return PullRequestChecks::Passed === $current->checks
            && ($current->checksConcludedSince($previous) || $previous->draft)
            && PullRequestState::Open === $current->state
            && !$current->draft;
    }
}
