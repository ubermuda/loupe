<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\LegacyFingerprintGroup;

/** A fact provider of Bridge. Bridge is always on. */
abstract readonly class BridgeFactProvider implements FactProvider, LegacyFingerprintGroup
{
    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.bridge';
    }
}
