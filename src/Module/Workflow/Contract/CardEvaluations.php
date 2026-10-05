<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Asks the engine to evaluate cards again after their facts changed. */
interface CardEvaluations
{
    /** @param list<string|Uuid> $cardIds */
    public function forCards(array $cardIds): void;

    /** True while the engine can run on this instance. A listener checks it before its own repository read. */
    public function isOn(): bool;
}
