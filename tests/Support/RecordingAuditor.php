<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\LoupeAuditLoggerRegistry;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\AuditEvent;
use App\Module\Audit\Auditor;
use App\Module\Audit\MonologAuditSink;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * An Auditor that keeps both what it recorded and the log line the Monolog sink
 * emitted for it, so a migrated call site can assert the record and the
 * surviving log line together. The two loggers stand in for the `app` and
 * `app_security` channels the real registry binds.
 */
final class RecordingAuditor
{
    public Auditor $auditor;

    public FakeAuditSink $sink;

    public RecordingLogger $domainChannel;

    public RecordingLogger $securityChannel;

    public function __construct(AuditActorProviderInterface $actorProvider)
    {
        $this->sink = new FakeAuditSink();
        $this->domainChannel = new RecordingLogger();
        $this->securityChannel = new RecordingLogger();

        $registry = new LoupeAuditLoggerRegistry($this->domainChannel, $this->securityChannel);

        $this->auditor = new Auditor(
            [new MonologAuditSink($registry, new NullLogger()), $this->sink],
            $actorProvider,
            new NullLogger(),
            new MockClock(),
        );
    }

    /** The single record for an operation; fails when there is none or several. */
    public function record(string $operation): AuditEvent
    {
        $matching = array_values(array_filter(
            $this->sink->events,
            static fn (AuditEvent $event): bool => $event->operation === $operation,
        ));

        Assert::assertCount(1, $matching, sprintf('expected exactly one "%s" audit record', $operation));

        return $matching[0];
    }

    /** @return list<string> */
    public function operations(): array
    {
        return array_map(static fn (AuditEvent $event): string => $event->operation, $this->sink->events);
    }

    /** @return list<string> */
    public function domainLogLines(): array
    {
        return $this->lines($this->domainChannel);
    }

    /** @return list<string> */
    public function securityLogLines(): array
    {
        return $this->lines($this->securityChannel);
    }

    /** @return list<string> */
    private function lines(RecordingLogger $logger): array
    {
        return array_map(
            static fn (array $entry): string => $entry['message'],
            $logger->records,
        );
    }
}
