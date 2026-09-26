<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use Sentry\ClientInterface;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SentryDisabledByDefaultTest extends KernelTestCase
{
    public function test_an_empty_dsn_leaves_the_client_with_nowhere_to_send(): void
    {
        self::bootKernel();

        $hub = self::getContainer()->get(HubInterface::class);
        self::assertInstanceOf(HubInterface::class, $hub);
        $client = $hub->getClient();
        self::assertInstanceOf(ClientInterface::class, $client);

        self::assertNull($client->getOptions()->getDsn());
    }

    public function test_the_test_environment_records_no_traces(): void
    {
        self::bootKernel();

        self::assertFalse(self::getContainer()->getParameter('sentry.tracing.enabled'));
    }
}
