<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Command;

use App\Module\Forge\Command\ForgeDeliveryOutcome;
use App\Module\Forge\Command\ReceiveForgeDeliveryCommand;
use App\Module\Forge\Command\ReceiveForgeDeliveryHandler;
use App\Module\Forge\Event\ForgeDeliveryReceived;
use App\Module\Forge\ForgeAdapterInterface;
use App\Module\Forge\ForgeAdapters;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\Forge\InvalidForgeSignature;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\AuditActorContext;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class ReceiveForgeDeliveryHandlerTest extends TestCase
{
    public function test_an_unknown_forge_is_reported_and_nothing_is_announced(): void
    {
        $events = $this->recordingDispatcher();
        $handler = $this->handler($events, adapter: null);

        $outcome = $handler(new ReceiveForgeDeliveryCommand('gitea', new \Symfony\Component\HttpFoundation\Request()));

        self::assertSame(ForgeDeliveryOutcome::UnknownForge, $outcome);
        self::assertSame([], $events->dispatched);
    }

    public function test_an_unverified_delivery_is_refused_audited_and_never_announced(): void
    {
        $events = $this->recordingDispatcher();
        $logger = new RecordingLogger();
        $audit = $this->audit();
        $handler = $this->handler(
            $events,
            adapter: $this->adapter('github', new InvalidForgeSignature('bad digest')),
            logger: $logger,
            auditor: $audit->auditor,
        );

        $outcome = $handler(new ReceiveForgeDeliveryCommand('github', new \Symfony\Component\HttpFoundation\Request()));

        self::assertSame(ForgeDeliveryOutcome::InvalidSignature, $outcome);
        self::assertSame([], $events->dispatched);

        self::assertCount(1, $audit->sink->events);
        self::assertSame('forge.delivery_rejected', $audit->sink->events[0]->operation);
        self::assertSame(AuditOutcome::Refused, $audit->sink->events[0]->outcome);

        self::assertCount(1, $logger->records);
        self::assertSame('forge.delivery_rejected', $logger->records[0]['message']);
        self::assertSame('github', $logger->records[0]['context']['forge']);
    }

    public function test_a_delivery_that_carries_no_signal_is_accepted_and_never_announced(): void
    {
        $events = $this->recordingDispatcher();
        $handler = $this->handler($events, adapter: $this->adapter('github', []));

        $outcome = $handler(new ReceiveForgeDeliveryCommand('github', new \Symfony\Component\HttpFoundation\Request()));

        self::assertSame(ForgeDeliveryOutcome::Received, $outcome);
        self::assertSame([], $events->dispatched);
    }

    public function test_a_verified_delivery_is_announced_whole(): void
    {
        $delivery = new ForgeDelivery(ForgeEventType::MERGED, 'github', 'ubermuda/loupe', 548);
        $events = $this->recordingDispatcher();
        $handler = $this->handler($events, adapter: $this->adapter('github', [$delivery]));

        $outcome = $handler(new ReceiveForgeDeliveryCommand('github', new \Symfony\Component\HttpFoundation\Request()));

        self::assertSame(ForgeDeliveryOutcome::Received, $outcome);
        self::assertCount(1, $events->dispatched);
        $announced = $events->dispatched[0];
        self::assertInstanceOf(ForgeDeliveryReceived::class, $announced);
        self::assertSame([$delivery], $announced->deliveries);
    }

    /**
     * @param list<ForgeDelivery>|\Throwable $answer
     */
    private function adapter(string $forge, array|\Throwable $answer): ForgeAdapterInterface
    {
        return new readonly class($forge, $answer) implements ForgeAdapterInterface {
            /** @param list<ForgeDelivery>|\Throwable $answer */
            public function __construct(
                private string $forge,
                private array|\Throwable $answer,
            ) {
            }

            #[\Override]
            public function forge(): string
            {
                \assert('' !== $this->forge);

                return $this->forge;
            }

            #[\Override]
            public function translate(\Symfony\Component\HttpFoundation\Request $request): array
            {
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }

    private function handler(
        object $events,
        ?ForgeAdapterInterface $adapter,
        ?RecordingLogger $logger = null,
        ?Auditor $auditor = null,
    ): ReceiveForgeDeliveryHandler {
        \assert($events instanceof EventDispatcherInterface);

        return new ReceiveForgeDeliveryHandler(
            new ForgeAdapters(null === $adapter ? [] : [$adapter]),
            $events,
            $logger ?? new RecordingLogger(),
            $auditor ?? $this->audit()->auditor,
        );
    }

    private function audit(): RecordingAuditor
    {
        return new RecordingAuditor(new class implements AuditActorProviderInterface {
            #[\Override]
            public function currentActor(): AuditActorContext
            {
                return new AuditActorContext(null, null, 'webhook');
            }
        });
    }

    /** @return EventDispatcherInterface&object{dispatched: list<object>} */
    private function recordingDispatcher(): object
    {
        return new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $dispatched = [];

            #[\Override]
            public function dispatch(object $event): object
            {
                $this->dispatched[] = $event;

                return $event;
            }
        };
    }
}
