<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

enum DocumentStatus: string
{
    case InReview = 'in-review';
    case Approved = 'approved';
    case ChangesRequested = 'changes-requested';

    public function translationKey(): string
    {
        return match ($this) {
            self::InReview => 'document.status.in_review',
            self::Approved => 'document.status.approved',
            self::ChangesRequested => 'document.status.changes_requested',
        };
    }
}
