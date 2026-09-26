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

    /** @return iterable<string, array{class-string, list<string>}> */
    public static function workerEvents(): iterable
    {
        yield 'received' => [WorkerMessageReceivedEvent::class, [
            MessengerListener::class.'::handleWorkerMessageReceivedEvent',
            MessageTracingSubscriber::class.'::onReceived',
        ]];
        yield 'handled' => [WorkerMessageHandledEvent::class, [
            MessageTracingSubscriber::class.'::onHandled',
            MessengerListener::class.'::handleWorkerMessageHandledEvent',
        ]];
        yield 'failed' => [WorkerMessageFailedEvent::class, [
            MessageTracingSubscriber::class.'::onFailed',
            MessengerListener::class.'::handleWorkerMessageFailedEvent',
            MessageTracingSubscriber::class.'::restoreAfterFailure',
        ]];
    }

    /** @param list<string> $expected */
    #[DataProvider('workerEvents')]
    public function test_the_message_transaction_nests_inside_the_bundle_messenger_listener(string $event, array $expected): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $order = array_values(array_filter(array_map(
            static fn (mixed $listener): ?string => \is_array($listener) && \is_object($listener[0]) && \is_string($listener[1] ?? null) ? $listener[0]::class.'::'.$listener[1] : null,
            $dispatcher->getListeners($event),
        ), static fn (?string $name): bool => null !== $name && (str_starts_with($name, MessageTracingSubscriber::class.'::') || str_starts_with($name, MessengerListener::class.'::'))));

        self::assertSame($expected, $order);
    }
}
