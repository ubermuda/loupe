<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryEventScrubber;
use App\Observability\TracesSampler;
use Sentry\ClientInterface;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
    }
}
