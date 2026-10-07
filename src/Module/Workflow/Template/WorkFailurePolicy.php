<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** What the engine does when a bridge settles the work request of a rule as refused. */
final readonly class WorkFailurePolicy
{
    /**
     * @param list<string> $retryOn        the refusal codes that earn a retry, and any other code pauses the card at once
     * @param int          $retries        how many retries run before the card pauses
     * @param list<int>    $backoffMinutes the wait before each retry
     */
    public function __construct(
        public array $retryOn,
        public int $retries,
        public array $backoffMinutes,
    ) {
    }

    public function retries(string $code): bool
    {
        return \in_array($code, $this->retryOn, true);
    }
}
