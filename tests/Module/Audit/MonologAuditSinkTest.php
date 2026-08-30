<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\AuditEvent;
use App\Module\Audit\AuditLoggerRegistryInterface;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditSubject;
use App\Module\Audit\MonologAuditSink;
use App\Tests\Support\FakeAuditActor;
use App\Tests\Support\FakeAuditCredential;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class MonologAuditSinkTest extends TestCase
{
    private RecordingLogger $domainLogger;
    private RecordingLogger $securityLogger;
    private RecordingLogger $fallbackLogger;
    private MonologAuditSink $sink;

    protected function setUp(): void
    {
        $this->domainLogger = new RecordingLogger();
        $this->securityLogger = new RecordingLogger();
        $this->fallbackLogger = new RecordingLogger();

        $registry = new readonly class($this->domainLogger, $this->securityLogger) implements AuditLoggerRegistryInterface {
            public function __construct(
                private LoggerInterface $domain,
                private LoggerInterface $security,
            ) {
            }

            #[\Override]
            public function loggerFor(string $category): ?LoggerInterface
            {
                return match ($category) {
                    Auditor::CATEGORY_DOMAIN => $this->domain,
                    Auditor::CATEGORY_SECURITY => $this->security,
                    default => null,
                };
            }
        };

        $this->sink = new MonologAuditSink($registry, $this->fallbackLogger);
    }

    public function test_a_domain_event_goes_to_the_domain_logger(): void
    {
        $this->sink->write($this->event(Auditor::CATEGORY_DOMAIN));

        self::assertCount(1, $this->domainLogger->records);
        self::assertSame([], $this->securityLogger->records);
        self::assertSame([], $this->fallbackLogger->records);
    }

    public function test_a_security_event_goes_to_the_security_logger(): void
    {
        $this->sink->write($this->event(Auditor::CATEGORY_SECURITY));

        self::assertCount(1, $this->securityLogger->records);
        self::assertSame([], $this->domainLogger->records);
        self::assertSame([], $this->fallbackLogger->records);
    }

    public function test_an_unmapped_category_falls_back_instead_of_throwing(): void
    {
        $this->sink->write($this->event('nothing-maps-this'));

        self::assertCount(1, $this->fallbackLogger->records);
        self::assertSame([], $this->domainLogger->records);
        self::assertSame([], $this->securityLogger->records);
    }

    public function test_the_record_carries_the_operation_at_info_with_its_context(): void
    {
        $this->sink->write($this->event(Auditor::CATEGORY_DOMAIN));

        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'document.deleted',
            'context' => [
                'documentId' => '42',
                'channel' => 'mcp',
                'subjectType' => 'document',
                'subjectId' => '42',
            ],
        ]], $this->domainLogger->records);
    }

    public function test_no_part_of_the_acting_identity_reaches_the_log_line(): void
    {
        $this->sink->write(new AuditEvent(
            'document.deleted',
            Auditor::CATEGORY_DOMAIN,
            new FakeAuditActor('Riley Chen', 'user-1'),
            new FakeAuditCredential('token-1'),
            'mcp',
            null,
            [],
            new \DateTimeImmutable('2026-08-29 12:00:00'),
        ));

        self::assertSame(['channel' => 'mcp'], $this->domainLogger->records[0]['context']);
    }

    public function test_flush_writes_nothing_because_the_sink_already_did(): void
    {
        $this->sink->write($this->event(Auditor::CATEGORY_DOMAIN));
        $this->sink->flush();

        self::assertCount(1, $this->domainLogger->records);
    }

    private function event(string $category): AuditEvent
    {
        return new AuditEvent(
            'document.deleted',
            $category,
            null,
            null,
            'mcp',
            new AuditSubject('document', '42'),
            ['documentId' => '42'],
            new \DateTimeImmutable('2026-08-29 12:00:00'),
        );
    }
}
