<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Application code injects this and never a sink. Deliberately not a
 * LoggerInterface: that has no room for a typed subject, and implementing it
 * would collide with Monolog during autowiring.
 */
final readonly class Auditor
{
    public const string CATEGORY_DOMAIN = 'domain';
    public const string CATEGORY_SECURITY = 'security';

    /** @var array<AuditSinkInterface> */
    private array $sinks;

    /**
     * Materialized because the facade iterates the sinks on every call: a bare
     * generator would yield them once and then throw, turning a caller's second
     * audit into an exception.
     *
     * @param iterable<AuditSinkInterface> $sinks
     */
    public function __construct(
        #[AutowireIterator('app.audit_sink')]
        iterable $sinks,
        private AuditActorProviderInterface $actorProvider,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
        $this->sinks = $sinks instanceof \Traversable ? iterator_to_array($sinks, false) : $sinks;
    }

    public function flush(): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->flush();
            } catch (\Throwable $e) {
                $this->reportSinkFailure($sink, 'flush', $e);
            }
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function record(string $operation, AuditOutcome $outcome, array $context = [], ?AuditSubject $subject = null, string $category = self::CATEGORY_DOMAIN): void
    {
        try {
            $event = $this->buildEvent($operation, $outcome, $context, $subject, $category);
        } catch (\Throwable $e) {
            $this->report('audit.actor_unresolved', ['operation' => $operation, 'exception' => $e]);
            $event = $this->unattributedEvent($operation, $outcome, $context, $subject, $category);
        }

        foreach ($this->sinks as $sink) {
            try {
                $sink->write($event);
            } catch (\Throwable $e) {
                $this->reportSinkFailure($sink, 'write', $e, $operation);
            }
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function buildEvent(string $operation, AuditOutcome $outcome, array $context, ?AuditSubject $subject, string $category): AuditEvent
    {
        $actor = $this->actorProvider->currentActor();

        return new AuditEvent(
            $operation,
            $outcome,
            $category,
            $actor->actor,
            $actor->credential,
            $actor->channel,
            $subject,
            // Union, not array_merge: the caller's keys win, and neither side's
            // keys are renumbered.
            $context + $actor->context,
            $this->clock->now(),
        );
    }

    /**
     * Stands in when the identity cannot be resolved: dropping the record would
     * make an audited operation look like one that never happened. The marker
     * overrides a caller's key of the same name because it describes the record,
     * and the clock is not asked again because it is one of the things that throws.
     *
     * @param array<string, scalar|null> $context
     */
    private function unattributedEvent(string $operation, AuditOutcome $outcome, array $context, ?AuditSubject $subject, string $category): AuditEvent
    {
        return new AuditEvent(
            $operation,
            $outcome,
            $category,
            null,
            null,
            NullAuditActorProvider::CHANNEL,
            $subject,
            ['actorUnresolved' => true] + $context,
            new \DateTimeImmutable(),
        );
    }

    private function reportSinkFailure(AuditSinkInterface $sink, string $stage, \Throwable $e, ?string $operation = null): void
    {
        $this->report('audit.sink_failed', [
            'sink' => $sink::class,
            'stage' => $stage,
            'operation' => $operation,
            'exception' => $e,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function report(string $event, array $context): void
    {
        try {
            $this->logger->error($event, $context);
        } catch (\Throwable) {
            // A logger reaching the backend that just failed must not turn a
            // contained failure into one that interrupts the fan-out.
        }
    }
}
