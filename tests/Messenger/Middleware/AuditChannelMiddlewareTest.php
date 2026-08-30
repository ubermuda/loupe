<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Middleware;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Audit\LoupeAuditActorProvider;
use App\Messenger\Middleware\AuditChannelMiddleware;
use App\Messenger\Stamp\AuditChannelStamp;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Security\AuthenticatedApiTokenResolver;
use App\Module\Audit\AuditEvent;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Tests\Support\FakeAuditSink;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class AuditChannelMiddlewareTest extends TestCase
{
    private TokenStorage $tokenStorage;
    private AuditContext $auditContext;
    private FakeAuditSink $sink;
    private Auditor $auditor;
    private AuditChannelMiddleware $middleware;

    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
        $this->auditContext = new AuditContext();
        $this->sink = new FakeAuditSink();

        $apiTokens = $this->createStub(ApiTokenRepository::class);
        $apiTokens->method('find')->willReturn(null);

        $provider = new LoupeAuditActorProvider(
            $this->tokenStorage,
            new AuthenticatedApiTokenResolver($this->tokenStorage, $apiTokens),
            $this->auditContext,
        );

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-29 12:00:00'));

        $this->auditor = new Auditor([$this->sink], $provider, new NullLogger(), $clock);
        $this->middleware = new AuditChannelMiddleware($this->auditContext, $provider);
    }

    public function test_a_dispatch_stamps_the_channel_it_was_dispatched_under(): void
    {
        $this->signIn();

        $handled = $this->middleware->handle(new Envelope(new \stdClass()), $this->stackRunning(static function (): void {}));

        self::assertEquals(new AuditChannelStamp(AuditChannel::Session), $handled->last(AuditChannelStamp::class));
    }

    public function test_a_worker_records_the_dispatching_channel_and_marks_the_write_async(): void
    {
        $envelope = new Envelope(new \stdClass(), [
            new AuditChannelStamp(AuditChannel::Session),
            new ReceivedStamp('async'),
            new ConsumedByWorkerStamp(),
        ]);

        $this->middleware->handle($envelope, $this->stackRunning(fn () => $this->auditor->record('export.generated', AuditOutcome::Success)));

        self::assertSame(AuditChannel::Session->value, $this->recordedEvent()->channel);
        self::assertSame(['async' => true], $this->recordedEvent()->context);
    }

    public function test_an_unstamped_message_off_the_scheduler_transport_is_a_cron_tick(): void
    {
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('scheduler_default'), new ConsumedByWorkerStamp()]);

        $this->middleware->handle($envelope, $this->stackRunning(fn () => $this->auditor->record('trial_sweep.completed', AuditOutcome::Success)));

        self::assertSame(AuditChannel::Cron->value, $this->recordedEvent()->channel);
        self::assertSame(['async' => true], $this->recordedEvent()->context);
    }

    /**
     * A message queued before this middleware shipped, replayed from `failed`,
     * or sent straight to a transport arrives unstamped too. Only the scheduler
     * transport makes an unstamped message a cron tick.
     */
    public function test_an_unstamped_message_off_any_other_transport_has_unknown_provenance(): void
    {
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new ConsumedByWorkerStamp()]);

        $this->middleware->handle($envelope, $this->stackRunning(fn () => $this->auditor->record('export.generated', AuditOutcome::Success)));

        self::assertSame(AuditChannel::System->value, $this->recordedEvent()->channel);
        self::assertSame(['async' => true], $this->recordedEvent()->context);
    }

    public function test_the_callers_own_context_key_wins_over_the_async_marker(): void
    {
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new ConsumedByWorkerStamp()]);

        $this->middleware->handle($envelope, $this->stackRunning(fn () => $this->auditor->record('x', AuditOutcome::Success, ['async' => false])));

        self::assertSame(['async' => false], $this->recordedEvent()->context);
    }

    public function test_the_worker_channel_does_not_leak_past_the_message(): void
    {
        $this->auditContext->channel = AuditChannel::Console;
        $envelope = new Envelope(new \stdClass(), [
            new AuditChannelStamp(AuditChannel::Session),
            new ReceivedStamp('async'),
            new ConsumedByWorkerStamp(),
        ]);

        $this->middleware->handle($envelope, $this->stackRunning(static function (): void {}));

        self::assertSame(AuditChannel::Console, $this->auditContext->channel);
        self::assertSame([], $this->auditContext->ambientContext);
    }

    public function test_inline_handling_inside_a_request_is_not_async(): void
    {
        $this->signIn();
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('sync')]);

        $this->middleware->handle($envelope, $this->stackRunning(fn () => $this->auditor->record('mail.sent', AuditOutcome::Success)));

        self::assertSame(AuditChannel::Session->value, $this->recordedEvent()->channel);
        self::assertSame([], $this->recordedEvent()->context);
    }

    private function recordedEvent(): AuditEvent
    {
        self::assertCount(1, $this->sink->events);

        return $this->sink->events[0];
    }

    private function signIn(): void
    {
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('hasAttribute')->willReturn(false);
        $securityToken->method('getUser')->willReturn(new User('Riley Chen', 'riley@example.com', 'x'));

        $this->tokenStorage->setToken($securityToken);
    }

    private function stackRunning(\Closure $handler): StackInterface
    {
        return new readonly class($handler) implements StackInterface {
            public function __construct(
                private \Closure $handler,
            ) {
            }

            #[\Override]
            public function next(): MiddlewareInterface
            {
                return new readonly class($this->handler) implements MiddlewareInterface {
                    public function __construct(
                        private \Closure $handler,
                    ) {
                    }

                    #[\Override]
                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        ($this->handler)();

                        return $envelope;
                    }
                };
            }
        };
    }
}
