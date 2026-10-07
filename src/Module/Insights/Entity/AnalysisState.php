<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

enum AnalysisState: string
{
    case Waiting = 'waiting';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Paused = 'paused';

    public function isFinished(): bool
    {
        return self::Done === $this || self::Failed === $this;
    }
}
