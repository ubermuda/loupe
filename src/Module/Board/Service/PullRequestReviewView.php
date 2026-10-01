<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;

/** The review of a pull request as a card shows it, where an approval of an older head is outdated. */
enum PullRequestReviewView: string
{
    case Approved = 'approved';
    case ApprovalOutdated = 'approval-outdated';
    case ChangesRequested = 'changes-requested';
    case Required = 'required';
    case None = 'none';

    public static function of(ForgePullRequest $row): self
    {
        return match ($row->review) {
            PullRequestReview::Approved => PullRequestState::Open === $row->state && $row->approvalIsStale() ? self::ApprovalOutdated : self::Approved,
            PullRequestReview::ChangesRequested => self::ChangesRequested,
            PullRequestReview::Required => self::Required,
            PullRequestReview::None => self::None,
        };
    }
}
