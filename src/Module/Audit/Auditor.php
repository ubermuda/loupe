<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Application code injects this and never a sink. It mirrors PSR-3 without
 * implementing it: LoggerInterface has no room for a typed subject, and
 * implementing it would collide with Monolog during autowiring.
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

    /**
     * @param array<string, scalar|null> $context
     */
    public function debug(string $operation, array $context = [], ?AuditSubject $subject = null, string $category = self::CATEGORY_DOMAIN): void
    {
        $this->record(AuditLevel::Debug, $operation, $context, $subject, $category);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function info(string $operation, array $context = [], ?AuditSubject $subject = null, string $category = self::CATEGORY_DOMAIN): void
    {
        $this->record(AuditLevel::Info, $operation, $context, $subject, $category);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function warning(string $operation, array $context = [], ?AuditSubject $subject = null, string $category = self::CATEGORY_DOMAIN): void
    {
        $this->record(AuditLevel::Warning, $operation, $context, $subject, $category);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function error(string $operation, array $context = [], ?AuditSubject $subject = null, string $category = self::CATEGORY_DOMAIN): void
    {
        $this->record(AuditLevel::Error, $operation, $context, $subject, $category);
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
    private function record(AuditLevel $level, string $operation, array $context, ?AuditSubject $subject, string $category): void
    {
        $actor = $this->actorProvider->currentActor();

        $event = new AuditEvent(
            $operation,
            $level,
            $category,
            $actor->actor,
            $actor->actor?->auditLabel(),
            $actor->credential,
            $actor->channel,
            $subject,
            $context,
            $this->clock->now(),
        );

        foreach ($this->sinks as $sink) {
            try {
                $sink->write($event);
            } catch (\Throwable $e) {
                $this->reportSinkFailure($sink, 'write', $e, $operation);
            }
        }
    }

    private function reportSinkFailure(AuditSinkInterface $sink, string $stage, \Throwable $e, ?string $operation = null): void
    {
        $this->logger->error('audit.sink_failed', [
            'sink' => $sink::class,
            'stage' => $stage,
            'operation' => $operation,
            'exception' => $e,
        ]);
    }
}
