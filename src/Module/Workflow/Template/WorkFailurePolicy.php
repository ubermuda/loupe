<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** What the engine does when a bridge settles the work request of a rule as refused. */
final readonly class WorkFailurePolicy
{
    /**
     * @param list<string> $retryOn    the refusal codes that earn a retry, and any other code pauses the card at once
     * @param int          $retries    how many retries run before the card pauses
     * @param ?string      $repairKind the kind of the one repair request that opens when the retries run out, before the card pauses
     */
    public function __construct(
        public array $retryOn,
        public int $retries,
        public ?string $repairKind = null,
    ) {
    }

    public function retries(string $code): bool
    {
        return \in_array($code, $this->retryOn, true);
    }
}
