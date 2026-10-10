<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** Why a card has a state. Within one kind, the case order is the order of precedence. */
enum CardStateCode: string
{
    case Paused = 'paused';
    case RunStopped = 'run-stopped';
    case ChecksFailed = 'checks-failed';
    case Conflicting = 'conflicting';
    case ReadyNotMerged = 'ready-not-merged';

    case DocumentInReview = 'document-in-review';
    case WaitsForApproval = 'waits-for-approval';
    case OpenQuestion = 'open-question';

    case RunOpen = 'run-open';
    case WorkRequested = 'work-requested';
    case ForgeRequestPending = 'forge-request-pending';

    case HeldByBlocker = 'held-by-blocker';

    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    public function kind(): CardStateKind
    {
        return match ($this) {
            self::Paused, self::RunStopped, self::ChecksFailed, self::Conflicting, self::ReadyNotMerged => CardStateKind::Stuck,
            self::DocumentInReview, self::WaitsForApproval, self::OpenQuestion => CardStateKind::NeedsYou,
            self::RunOpen, self::WorkRequested, self::ForgeRequestPending => CardStateKind::Working,
            self::HeldByBlocker => CardStateKind::Waiting,
        };
    }

    public function translationKey(): string
    {
        return 'board.card_state.reason.'.str_replace('-', '_', $this->value);
    }

    /** The sentence that says what clears the reason. */
    public function remedyKey(): string
    {
        return 'board.card_state.remedy.'.str_replace('-', '_', $this->value);
    }
}
