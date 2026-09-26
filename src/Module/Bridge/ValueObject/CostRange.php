<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** How far back the cost chart looks, by the completion date of a card. */
enum CostRange: string
{
    case ThirtyDays = 'thirty-days';
    case NinetyDays = 'ninety-days';
    case AllTime = 'all-time';

    /** Null for all time. */
    public function startFrom(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return match ($this) {
            self::ThirtyDays => $now->modify('-30 days'),
            self::NinetyDays => $now->modify('-90 days'),
            self::AllTime => null,
        };
    }

    public function translationKey(): string
    {
        return 'bridge.worker_run_cost.range.'.str_replace('-', '_', $this->value);
    }
}
