<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/**
 * A fact provider that took over a group of facts the engine used to build. The fingerprint of such a provider
 * returns the exact value of the old group, so the engine can still compute the hash that rule states stored.
 */
interface LegacyFingerprintGroup
{
    /** The name the old group went by in the stored hash, such as "documents". */
    public function legacyGroup(): string;
}
