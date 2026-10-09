<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class LegacyFingerprintCount
{
    /**
     * @param int $legacyOnly the rule states that hold the legacy hash of the current facts and not the provider hash
     * @param int $current    the rule states that hold the provider hash of the current facts
     * @param int $changed    the rule states that hold neither, because the facts changed or the rule is gone
     * @param int $cards      the cards that were read
     */
    public function __construct(
        public int $legacyOnly,
        public int $current,
        public int $changed,
        public int $cards,
    ) {
    }
}
