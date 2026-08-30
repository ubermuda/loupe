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
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Drives the middleware through a real bus, real transports and a real Worker.
 * A synthetic one-step stack cannot reach the cases that actually break it —
 * retry, a nested dispatch from inside a handler, inline sync handling — so
 * those are exercised end to end here.
 */
final class AuditChannelBusTest extends TestCase
{
    private AuditContext $auditContext;
    private FakeAuditSink $sink;
    private Auditor $auditor;
    private InMemoryTransport $transport;
    private TokenStorage $tokenStorage;
    private object $outerMessage;
    private object $innerMessage;

    protected function setUp(): void
    {
        $this->auditContext = new AuditContext();
        $this->sink = new FakeAuditSink();
        $this->transport = new InMemoryTransport();
        $this->tokenStorage = new TokenStorage();

        // Two distinct message types, with no behaviour of their own: the bus
        // only ever routes and hands them to a closure.
        $this->outerMessage = new \stdClass();
        $this->innerMessage = new \ArrayObject();

        $apiTokens = $this->createStub(ApiTokenRepository::class);
        $apiTokens->method('find')->willReturn(null);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-29 12:00:00'));

        $this->auditor = new Auditor([$this->sink], $this->provider($apiTokens), new NullLogger(), $clock);
    }

    public function test_a_message_queued_by_a_signed_in_person_is_handled_as_theirs(): void
    {
        $this->signIn();
        $bus = $this->bus([$this->outerMessage::class => [fn () => $this->auditor->record('export.generated', AuditOutcome::Success)]]);

        $bus->dispatch($this->outerMessage);
        $this->tokenStorage->setToken(null);
        $this->runWorker($bus, 1);

        self::assertSame(AuditChannel::Session->value, $this->recordedEvent()->channel);
        self::assertSame(['async' => true], $this->recordedEvent()->context);
    }

    public function test_a_retry_keeps_the_channel_of_the_original_dispatch(): void
    {
        $this->signIn();

        $attempts = 0;
        $bus = $this->bus([$this->outerMessage::class => [function () use (&$attempts): void {
            ++$attempts;
            $this->auditor->record('export.generated', AuditOutcome::Success);

            if (1 === $attempts) {
                throw new \RuntimeException('transient');
            }
        }]]);

        $bus->dispatch($this->outerMessage);
        $this->tokenStorage->setToken(null);
        $this->runWorker($bus, 2, retrying: true);

        self::assertSame(2, $attempts);
        self::assertCount(2, $this->sink->events);
        self::assertSame(AuditChannel::Session->value, $this->sink->events[1]->channel);
        self::assertSame(['async' => true], $this->sink->events[1]->context);
    }

    public function test_a_dispatch_from_inside_a_handler_leaves_the_outer_channel_alone(): void
    {
        $this->signIn();

        $bus = null;
        $bus = $this->bus([
            $this->outerMessage::class => [function () use (&$bus): void {
                self::assertInstanceOf(MessageBusInterface::class, $bus);
                $bus->dispatch($this->innerMessage);

                $this->auditor->record('export.generated', AuditOutcome::Success);
            }],
            $this->innerMessage::class => [static function (): void {}],
        ]);

        $bus->dispatch($this->outerMessage);
        $this->tokenStorage->setToken(null);
        $this->runWorker($bus, 1);

        self::assertSame(AuditChannel::Session->value, $this->recordedEvent()->channel);
        self::assertSame(['async' => true], $this->recordedEvent()->context);

        $queued = array_values(array_filter(
            $this->transport->getSent(),
            fn (Envelope $envelope): bool => $envelope->getMessage() === $this->innerMessage,
        ));
        self::assertCount(1, $queued);
        self::assertEquals(new AuditChannelStamp(AuditChannel::Session), $queued[0]->last(AuditChannelStamp::class));

        self::assertNull($this->auditContext->channel);
        self::assertSame([], $this->auditContext->ambientContext);
    }

    /**
     * A scheduler tick is produced by its transport and never passes through the
     * dispatch side, so it reaches the worker with no channel stamp at all.
     */
    public function test_an_unstamped_message_off_the_scheduler_transport_is_a_cron_tick(): void
    {
        $bus = $this->bus([$this->outerMessage::class => [fn () => $this->auditor->record('trial_sweep.completed', AuditOutcome::Success)]]);

        $this->transport->send(new Envelope($this->outerMessage));
        $this->runWorker($bus, 1, receiver: 'scheduler_default');

        self::assertSame(AuditChannel::Cron->value, $this->recordedEvent()->channel);
        self::assertSame(['async' => true], $this->recordedEvent()->context);
    }

    /**
     * Everything else arriving unstamped — queued before this shipped, replayed
     * from `failed`, sent straight to a transport — has provenance nothing here
     * can recover, and must not be filed as a cron tick.
     */
    public function test_an_unstamped_message_off_any_other_transport_has_unknown_provenance(): void
    {
        $bus = $this->bus([$this->outerMessage::class => [fn () => $this->auditor->record('export.generated', AuditOutcome::Success)]]);

        $this->transport->send(new Envelope($this->outerMessage));
        $this->runWorker($bus, 1);

        self::assertSame(AuditChannel::System->value, $this->recordedEvent()->channel);
        self::assertSame(['async' => true], $this->recordedEvent()->context);
    }

    public function test_inline_handling_through_the_sync_transport_is_not_async(): void
    {
        $this->signIn();

        $bus = null;
        $senders = new ServiceLocator(['sync' => static function () use (&$bus): SyncTransport {
            self::assertInstanceOf(MessageBusInterface::class, $bus);

            return new SyncTransport($bus);
        }]);

        $bus = new MessageBus([
            new AuditChannelMiddleware($this->auditContext, $this->provider($this->createStub(ApiTokenRepository::class))),
            new SendMessageMiddleware(new SendersLocator([$this->outerMessage::class => ['sync']], $senders)),
            new HandleMessageMiddleware(new HandlersLocator([
                $this->outerMessage::class => [fn () => $this->auditor->record('mail.sent', AuditOutcome::Success)],
            ])),
        ]);

        $bus->dispatch($this->outerMessage);

        self::assertSame(AuditChannel::Session->value, $this->recordedEvent()->channel);
        self::assertSame([], $this->recordedEvent()->context);
        self::assertNull($this->auditContext->channel);
    }

    /**
     * @param array<class-string, list<callable>> $handlers
     */
    private function bus(array $handlers): MessageBus
    {
        $senders = new ServiceLocator(['async' => fn (): InMemoryTransport => $this->transport]);
        $sendersMap = array_fill_keys(array_keys($handlers), ['async']);

        return new MessageBus([
            new AuditChannelMiddleware($this->auditContext, $this->provider($this->createStub(ApiTokenRepository::class))),
            new SendMessageMiddleware(new SendersLocator($sendersMap, $senders)),
            new HandleMessageMiddleware(new HandlersLocator($handlers)),
        ]);
    }

    private function runWorker(MessageBusInterface $bus, int $messages, bool $retrying = false, string $receiver = 'async'): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($messages));

        if ($retrying) {
            $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
                new ServiceLocator(['async' => fn (): InMemoryTransport => $this->transport]),
                new ServiceLocator(['async' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(1, 0, 1, 0, 0)]),
            ));
        }

        new Worker([$receiver => $this->transport], $bus, $dispatcher)->run(['sleep' => 0]);
    }

    private function provider(ApiTokenRepository $apiTokens): LoupeAuditActorProvider
    {
        return new LoupeAuditActorProvider(
            $this->tokenStorage,
            new AuthenticatedApiTokenResolver($this->tokenStorage, $apiTokens),
            $this->auditContext,
        );
    }

    private function recordedEvent(): AuditEvent
    {
        self::assertCount(1, $this->sink->events);

        return $this->sink->events[0];
    }

    /**
     * The worker has neither of these, which is the point: the channel has to
     * survive the queue rather than be re-derived on the other side.
     */
    private function signIn(): void
    {
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('hasAttribute')->willReturn(false);
        $securityToken->method('getUser')->willReturn(new User('Riley Chen', 'riley@example.com', 'x'));

        $this->tokenStorage->setToken($securityToken);
    }
}
