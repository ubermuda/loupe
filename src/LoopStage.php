<?php

declare(strict_types=1);

namespace App;

/**
 * The four stages of the review "loop": an agent proposes, a human reviews,
 * the agent revises, the human approves. Rendered as the loop ribbon on the
 * document- and site-review screens. Lives in the root namespace so both the
 * Review and SiteReview modules can consume it without a cross-module import.
 */
enum LoopStage: string
{
    case Proposed = 'proposed';
    case InReview = 'in-review';
    case Revise = 'revise';
    case Approved = 'approved';

    /**
     * Position in loop order (Proposed=0 … Approved=3). Used by the ribbon to
     * classify each step as completed (< current), current (=), or upcoming (>).
     */
    public function ordinal(): int
    {
        return match ($this) {
            self::Proposed => 0,
            self::InReview => 1,
            self::Revise => 2,
            self::Approved => 3,
        };
    }

    public function translationKey(): string
    {
        return match ($this) {
            self::Proposed => 'loop.step.proposed',
            self::InReview => 'loop.step.in_review',
            self::Revise => 'loop.step.revise',
            self::Approved => 'loop.step.approved',
        };
    }
}
