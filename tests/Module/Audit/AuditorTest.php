<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\AuditActorContext;
use App\Module\Audit\AuditActorInterface;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\AuditCredentialInterface;
use App\Module\Audit\AuditLevel;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditSubject;
use App\Tests\Support\FakeAuditSink;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AuditorTest extends TestCase
{
    private const string NOW = '2026-08-29 10:11:12';

    /**
     * @return iterable<string, array{AuditLevel}>
     */
    public static function levels(): iterable
    {
        yield 'debug' => [AuditLevel::Debug];
        yield 'info' => [AuditLevel::Info];
        yield 'warning' => [AuditLevel::Warning];
        yield 'error' => [AuditLevel::Error];
    }

    #[DataProvider('levels')]
    public function test_each_level_builds_an_event_from_its_arguments_and_the_actor_context(AuditLevel $expected): void
    {
        $actor = new class implements AuditActorInterface {};
        $credential = new class implements AuditCredentialInterface {};
        $subject = new AuditSubject('document', 'doc-1');
        $sink = new FakeAuditSink();

        $auditor = new Auditor(
            [$sink],
            $this->actorProvider(new AuditActorContext($actor, $credential, 'mcp')),
            new RecordingLogger(),
            new MockClock(self::NOW),
        );

        $operation = 'review.document.renamed';
        $context = ['from' => 'a', 'to' => 'b'];

        match ($expected) {
            AuditLevel::Debug => $auditor->debug($operation, $context, $subject, Auditor::CATEGORY_SECURITY),
            AuditLevel::Info => $auditor->info($operation, $context, $subject, Auditor::CATEGORY_SECURITY),
            AuditLevel::Warning => $auditor->warning($operation, $context, $subject, Auditor::CATEGORY_SECURITY),
            AuditLevel::Error => $auditor->error($operation, $context, $subject, Auditor::CATEGORY_SECURITY),
        };

        self::assertCount(1, $sink->events);
        $event = $sink->events[0];

        self::assertSame('review.document.renamed', $event->operation);
        self::assertSame($expected, $event->level);
        self::assertSame(Auditor::CATEGORY_SECURITY, $event->category);
        self::assertSame(['from' => 'a', 'to' => 'b'], $event->context);
        self::assertSame($subject, $event->subject);
        self::assertSame($actor, $event->actor);
        self::assertSame($credential, $event->credential);
        self::assertSame('mcp', $event->channel);
        self::assertSame(self::NOW, $event->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_category_defaults_to_domain_and_context_to_empty(): void
    {
        $sink = new FakeAuditSink();
        $auditor = $this->auditor(new RecordingLogger(), $sink);

        $auditor->info('review.document.created');

        self::assertSame(Auditor::CATEGORY_DOMAIN, $sink->events[0]->category);
        self::assertSame([], $sink->events[0]->context);
        self::assertNull($sink->events[0]->subject);
    }

    public function test_a_failing_sink_neither_breaks_the_caller_nor_the_other_sinks(): void
    {
        $failing = new FakeAuditSink(writeFailure: new \RuntimeException('backend down'));
        $healthy = new FakeAuditSink();
        $logger = new RecordingLogger();

        $this->auditor($logger, $failing, $healthy)->info('review.document.created');

        self::assertSame([], $failing->events);
        self::assertCount(1, $healthy->events);

        self::assertCount(1, $logger->records);
        self::assertSame('audit.sink_failed', $logger->records[0]['message']);
        self::assertSame(FakeAuditSink::class, $logger->records[0]['context']['sink']);
        self::assertSame('write', $logger->records[0]['context']['stage']);
        self::assertSame('review.document.created', $logger->records[0]['context']['operation']);
    }

    public function test_flush_fans_out_to_every_sink(): void
    {
        $first = new FakeAuditSink();
        $second = new FakeAuditSink();

        $this->auditor(new RecordingLogger(), $first, $second)->flush();

        self::assertTrue($first->flushed);
        self::assertTrue($second->flushed);
    }

    public function test_a_failing_flush_is_contained_and_reported(): void
    {
        $failing = new FakeAuditSink(flushFailure: new \RuntimeException('backend down'));
        $healthy = new FakeAuditSink();
        $logger = new RecordingLogger();

        $this->auditor($logger, $failing, $healthy)->flush();

        self::assertFalse($failing->flushed);
        self::assertTrue($healthy->flushed);

        self::assertCount(1, $logger->records);
        self::assertSame('audit.sink_failed', $logger->records[0]['message']);
        self::assertSame('flush', $logger->records[0]['context']['stage']);
    }

    public function test_sinks_supplied_as_a_generator_survive_more_than_one_call(): void
    {
        $first = new FakeAuditSink();
        $second = new FakeAuditSink();

        $sinks = (static function () use ($first, $second): \Generator {
            yield $first;
            yield $second;
        })();

        $auditor = new Auditor(
            $sinks,
            $this->actorProvider(new AuditActorContext(null, null, 'web')),
            new RecordingLogger(),
            new MockClock(self::NOW),
        );

        $auditor->info('review.document.created');
        $auditor->info('review.document.renamed');
        $auditor->flush();

        self::assertCount(2, $first->events);
        self::assertCount(2, $second->events);
        self::assertTrue($first->flushed);
        self::assertTrue($second->flushed);
    }

    private function auditor(RecordingLogger $logger, FakeAuditSink ...$sinks): Auditor
    {
        return new Auditor(
            $sinks,
            $this->actorProvider(new AuditActorContext(null, null, 'web')),
            $logger,
            new MockClock(self::NOW),
        );
    }

    private function actorProvider(AuditActorContext $context): AuditActorProviderInterface
    {
        return new readonly class($context) implements AuditActorProviderInterface {
            public function __construct(
                private AuditActorContext $context,
            ) {
            }

            #[\Override]
            public function currentActor(): AuditActorContext
            {
                return $this->context;
            }
        };
    }
}
