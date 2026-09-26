<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** What the parts of one bar of the cost chart stand for. */
enum CostSplit: string
{
    case None = 'none';
    case Rule = 'rule';
    case Model = 'model';

    public function translationKey(): string
    {
        return 'bridge.worker_run_cost.split.'.$this->value;
    }
}
