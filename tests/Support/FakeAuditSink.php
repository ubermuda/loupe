<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Audit\AuditEvent;
use App\Module\Audit\AuditSinkInterface;

/**
 * Collects the events the Auditor hands it, so a call-site test can assert what
 * was audited. Passing a failure makes it stand in for a broken backend.
 */
final class FakeAuditSink implements AuditSinkInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public bool $flushed = false;

    public function __construct(
        public ?\Throwable $writeFailure = null,
        public ?\Throwable $flushFailure = null,
    ) {
    }

    #[\Override]
    public function write(AuditEvent $event): void
    {
        if (null !== $this->writeFailure) {
            throw $this->writeFailure;
        }

        $this->events[] = $event;
    }

    #[\Override]
    public function flush(): void
    {
        if (null !== $this->flushFailure) {
            throw $this->flushFailure;
        }

        $this->flushed = true;
    }
}
