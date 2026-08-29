<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Psr\Log\LoggerInterface;

/**
 * Writes every event straight to the logger its category maps to, so audit
 * records keep their place in the log stream — which is why flush() has nothing
 * left to do.
 */
final readonly class MonologAuditSink implements AuditSinkInterface
{
    public function __construct(
        private AuditLoggerRegistryInterface $loggers,
        private LoggerInterface $fallbackLogger,
    ) {
    }

    #[\Override]
    public function write(AuditEvent $event): void
    {
        $logger = $this->loggers->loggerFor($event->category) ?? $this->fallbackLogger;

        $logger->log($event->level->psrLogLevel(), $event->operation, $this->contextFor($event));
    }

    #[\Override]
    public function flush(): void
    {
    }

    /**
     * @return array<string, scalar|null>
     */
    private function contextFor(AuditEvent $event): array
    {
        $context = $event->context;
        $context['channel'] = $event->channel;

        // The actor and credential markers carry no readable identity, so the
        // class is all there is to record. Never the object: Monolog would
        // normalize a User, password hash included, into the log line.
        if (null !== $event->actor) {
            $context['actor'] = $event->actor::class;
        }

        if (null !== $event->credential) {
            $context['credential'] = $event->credential::class;
        }

        if (null !== $event->subject) {
            $context['subjectType'] = $event->subject->type;
            $context['subjectId'] = $event->subject->id;
        }

        return $context;
    }
}
