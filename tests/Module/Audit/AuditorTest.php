<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\AuditActorContext;
use App\Module\Audit\AuditActorInterface;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Audit\NullAuditActorProvider;
use App\Tests\Support\FakeAuditActor;
use App\Tests\Support\FakeAuditCredential;
use App\Tests\Support\FakeAuditSink;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

final class AuditorTest extends TestCase
{
    private const string NOW = '2026-08-29 10:11:12';

    public function test_the_event_is_built_from_the_arguments_and_the_actor_context(): void
    {
        $actor = new FakeAuditActor('Riley Chen', 'user-1');
        $credential = new FakeAuditCredential('token-1');
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

        $auditor->record($operation, AuditOutcome::Refused, $context, $subject, Auditor::CATEGORY_SECURITY);

        self::assertCount(1, $sink->events);
        $event = $sink->events[0];

        self::assertSame('review.document.renamed', $event->operation);
        self::assertSame(AuditOutcome::Refused, $event->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $event->category);
        self::assertSame(['from' => 'a', 'to' => 'b'], $event->context);
        self::assertSame($subject, $event->subject);
        self::assertSame($actor, $event->actor);
        self::assertSame('Riley Chen', $event->actorLabel);
        self::assertSame('user-1', $event->actorIdentifier);
        self::assertSame($credential, $event->credential);
        self::assertSame('token-1', $event->credentialIdentifier);
        self::assertSame('mcp', $event->channel);
        self::assertSame(self::NOW, $event->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_the_label_is_read_from_the_actor_on_every_record_rather_than_snapshotted_once(): void
    {
        $actor = new FakeAuditActor('Riley Chen', 'user-1');
        $sink = new FakeAuditSink();

        $auditor = new Auditor(
            [$sink],
            $this->actorProvider(new AuditActorContext($actor, null, 'session')),
            new RecordingLogger(),
            new MockClock(self::NOW),
        );

        $auditor->record('review.document.created', AuditOutcome::Success);
        $actor->label = 'Riley Chen-Okafor';
        $auditor->record('review.document.renamed', AuditOutcome::Success);

        self::assertSame('Riley Chen', $sink->events[0]->actorLabel);
        self::assertSame('Riley Chen-Okafor', $sink->events[1]->actorLabel);
    }

    public function test_an_identifier_that_changes_after_the_record_does_not_change_the_event(): void
    {
        $actor = new FakeAuditActor('Riley Chen', 'user-1');
        $credential = new FakeAuditCredential('token-1');
        $sink = new FakeAuditSink();

        $auditor = new Auditor(
            [$sink],
            $this->actorProvider(new AuditActorContext($actor, $credential, 'session')),
            new RecordingLogger(),
            new MockClock(self::NOW),
        );

        $auditor->record('review.document.created', AuditOutcome::Success);
        $actor->identifier = 'user-2';
        $credential->identifier = 'token-2';

        self::assertSame('user-1', $sink->events[0]->actorIdentifier);
        self::assertSame('token-1', $sink->events[0]->credentialIdentifier);
    }

    /** An actor the application declines to name is not the same as no actor at all. */
    public function test_an_actor_reporting_no_label_still_reaches_the_sink_with_its_identifier(): void
    {
        $actor = new FakeAuditActor(null, 'user-1');
        $sink = new FakeAuditSink();

        $auditor = new Auditor(
            [$sink],
            $this->actorProvider(new AuditActorContext($actor, null, 'session')),
            new RecordingLogger(),
            new MockClock(self::NOW),
        );

        $auditor->record('review.document.created', AuditOutcome::Success);

        self::assertSame($actor, $sink->events[0]->actor);
        self::assertNull($sink->events[0]->actorLabel);
        self::assertSame('user-1', $sink->events[0]->actorIdentifier);
    }

    public function test_an_unattributed_record_carries_no_label(): void
    {
        $sink = new FakeAuditSink();

        $this->auditor(new RecordingLogger(), $sink)->record('review.document.created', AuditOutcome::Success);

        self::assertNull($sink->events[0]->actor);
        self::assertNull($sink->events[0]->actorLabel);
    }

    public function test_category_defaults_to_domain_and_context_to_empty(): void
    {
        $sink = new FakeAuditSink();
        $auditor = $this->auditor(new RecordingLogger(), $sink);

        $auditor->record('review.document.created', AuditOutcome::Success);

        self::assertSame(Auditor::CATEGORY_DOMAIN, $sink->events[0]->category);
        self::assertSame([], $sink->events[0]->context);
        self::assertNull($sink->events[0]->subject);
    }

    /**
     * @return iterable<string, array{AuditActorProviderInterface}>
     */
    public static function brokenIdentityResolution(): iterable
    {
        yield 'the provider itself throws' => [new readonly class implements AuditActorProviderInterface {
            #[\Override]
            public function currentActor(): AuditActorContext
            {
                throw new \RuntimeException('the security token is gone');
            }
        }];

        yield 'the actor cannot say what it is called' => [new readonly class implements AuditActorProviderInterface {
            #[\Override]
            public function currentActor(): AuditActorContext
            {
                return new AuditActorContext(new readonly class implements AuditActorInterface {
                    #[\Override]
                    public function auditLabel(): ?string
                    {
                        throw new \RuntimeException('the actor is detached');
                    }

                    #[\Override]
                    public function auditIdentifier(): string
                    {
                        return 'user-1';
                    }
                }, null, 'session');
            }
        }];
    }

    #[DataProvider('brokenIdentityResolution')]
    public function test_an_unresolvable_identity_is_recorded_as_unattributed_rather_than_dropped(AuditActorProviderInterface $provider): void
    {
        $sink = new FakeAuditSink();
        $logger = new RecordingLogger();

        new Auditor([$sink], $provider, $logger, new MockClock(self::NOW))
            ->record('review.document.created', AuditOutcome::Refused, ['actorUnresolved' => false, 'documentId' => 'doc-1']);

        self::assertCount(1, $sink->events);
        $event = $sink->events[0];

        self::assertSame('review.document.created', $event->operation);
        self::assertSame(AuditOutcome::Refused, $event->outcome, 'A refusal whose actor could not be resolved is still a refusal.');
        self::assertNull($event->actor);
        self::assertNull($event->actorLabel);
        self::assertNull($event->actorIdentifier);
        self::assertNull($event->credential);
        self::assertNull($event->credentialIdentifier);
        self::assertSame(NullAuditActorProvider::CHANNEL, $event->channel);
        self::assertTrue($event->context['actorUnresolved']);
        self::assertSame('doc-1', $event->context['documentId']);

        self::assertCount(1, $logger->records);
        self::assertSame('audit.actor_unresolved', $logger->records[0]['message']);
        self::assertSame('review.document.created', $logger->records[0]['context']['operation']);
    }

    /** The reporting path runs on a backend that has just failed, so it is the likeliest thing to fail next. */
    public function test_a_logger_that_throws_does_not_stop_the_remaining_sinks(): void
    {
        $failing = new FakeAuditSink(writeFailure: new \RuntimeException('backend down'));
        $healthy = new FakeAuditSink();

        $logger = new class extends AbstractLogger {
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('the log handler shares the failing backend');
            }
        };

        $auditor = new Auditor(
            [$failing, $healthy],
            $this->actorProvider(new AuditActorContext(null, null, 'web')),
            $logger,
            new MockClock(self::NOW),
        );

        $auditor->record('review.document.created', AuditOutcome::Success);
        $auditor->flush();

        self::assertCount(1, $healthy->events);
        self::assertTrue($healthy->flushed);
    }

    public function test_a_failing_sink_neither_breaks_the_caller_nor_the_other_sinks(): void
    {
        $failing = new FakeAuditSink(writeFailure: new \RuntimeException('backend down'));
        $healthy = new FakeAuditSink();
        $logger = new RecordingLogger();

        $this->auditor($logger, $failing, $healthy)->record('review.document.created', AuditOutcome::Success);

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

        $auditor->record('review.document.created', AuditOutcome::Success);
        $auditor->record('review.document.renamed', AuditOutcome::Success);
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
