<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\LegacyFingerprintGroup;

/** A fact provider of Board. Board is always on. */
abstract readonly class BoardFactProvider implements FactProvider, LegacyFingerprintGroup
{
    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
