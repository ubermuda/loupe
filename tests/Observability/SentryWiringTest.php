<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\MessageTracingSubscriber;
use App\Observability\SentryEventScrubber;
use App\Observability\TracesSampler;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\ClientInterface;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class SentryWiringTest extends KernelTestCase
{
    public function test_the_client_uses_the_sampler_and_the_scrubber(): void
    {
        self::bootKernel();

        $hub = self::getContainer()->get(HubInterface::class);
        self::assertInstanceOf(HubInterface::class, $hub);
        $client = $hub->getClient();
        self::assertInstanceOf(ClientInterface::class, $client);
        $options = $client->getOptions();

        self::assertInstanceOf(TracesSampler::class, $options->getTracesSampler());
        self::assertNull($options->getTracesSampleRate());
        self::assertInstanceOf(SentryEventScrubber::class, $options->getBeforeSendCallback());
        self::assertInstanceOf(SentryEventScrubber::class, $options->getBeforeSendTransactionCallback());
        self::assertSame([], $options->getTracePropagationTargets());
    }

    /** @return iterable<string, array{class-string, bool}> */
    public static function workerEvents(): iterable
    {
        yield 'received' => [WorkerMessageReceivedEvent::class, false];
        yield 'handled' => [WorkerMessageHandledEvent::class, true];
        yield 'failed' => [WorkerMessageFailedEvent::class, true];
    }

    #[DataProvider('workerEvents')]
    public function test_the_message_transaction_nests_inside_the_bundle_messenger_listener(string $event, bool $runsFirst): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $order = array_values(array_filter(array_map(
            static fn (mixed $listener): ?string => \is_array($listener) && \is_object($listener[0]) ? $listener[0]::class : null,
            $dispatcher->getListeners($event),
        ), static fn (?string $class): bool => \in_array($class, [MessageTracingSubscriber::class, MessengerListener::class], true)));

        self::assertSame(
            $runsFirst ? [MessageTracingSubscriber::class, MessengerListener::class] : [MessengerListener::class, MessageTracingSubscriber::class],
            $order,
        );
    }
}
