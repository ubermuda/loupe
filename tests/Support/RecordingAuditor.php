<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\LoupeAuditLoggerRegistry;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\AuditEvent;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\MonologAuditSink;

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

    /**
     * Puts a recording Auditor in the container, for a call site whose own
     * dependencies are too many to rebuild by hand. Call it before the service
     * under test is fetched: the container hands out the replacement only to
     * what it builds afterwards.
     */
    public static function installedIn(ContainerInterface $container): self
    {
        $actors = $container->get(AuditActorProviderInterface::class);
        Assert::assertInstanceOf(AuditActorProviderInterface::class, $actors);

        $audit = new self($actors);
        $container->set(Auditor::class, $audit->auditor);

        return $audit;
    }

    /**
     * Drops everything recorded so far, for a test whose fixture setup runs a
     * handler that records as well as the one under test.
     */
    public function forget(): void
    {
        $this->sink->events = [];
        $this->domainChannel->records = [];
        $this->securityChannel->records = [];
    }

    /** The single record for an operation; fails when there is none or several. */
    public function record(string $operation): AuditEvent
    {
        $matching = $this->records($operation);

        Assert::assertCount(1, $matching, sprintf('expected exactly one "%s" audit record', $operation));

        return $matching[0];
    }

    /**
     * Every record for an operation, in the order they were written.
     *
     * @return list<AuditEvent>
     */
    public function records(string $operation): array
    {
        return array_values(array_filter(
            $this->sink->events,
            static fn (AuditEvent $event): bool => $event->operation === $operation,
        ));
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
