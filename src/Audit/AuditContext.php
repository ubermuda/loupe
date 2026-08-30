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

    #[\Override]
    public function reset(): void
    {
        $this->channel = null;
        $this->ambientContext = [];
    }
}
