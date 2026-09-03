<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\NullAuditActorProvider;

/**
 * An Auditor with no sinks, for a construction site that has to supply one and
 * asserts nothing about the trail. Use RecordingAuditor where the records are
 * what the test is about.
 */
final class SilentAuditor
{
    public static function create(): Auditor
    {
        return new Auditor([], new NullAuditActorProvider(), new NullLogger(), new MockClock());
    }
}
