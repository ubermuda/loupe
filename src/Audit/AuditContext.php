<?php

declare(strict_types=1);

namespace App\Audit;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The channel and extra context a process is currently acting under, for the
 * cases neither a request nor a security token can reveal.
 *
 * Mutable and long-lived, which is what ResetInterface is here for: a messenger
 * worker outlives any one message, and without a reset between them one
 * message's channel would be recorded against the next.
 */
final class AuditContext implements ResetInterface
{
    public ?AuditChannel $channel = null;

    /** @var array<string, scalar|null> */
    public array $ambientContext = [];

    /**
     * The actor being erased, if one is. Set for the rest of the request once
     * an account deletion starts, so the records the deletion itself writes
     * carry no name for the account they erase. The purger has already removed
     * that account's rows by the time these are buffered, so nothing else would
     * ever take the name back out.
     */
    public ?string $erasedActorId = null;

    #[\Override]
    public function reset(): void
    {
        $this->channel = null;
        $this->ambientContext = [];
        $this->erasedActorId = null;
    }
}
