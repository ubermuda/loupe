<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Console;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Expired archives are deleted only because the purge command is attached to
 * the `default` schedule, which the production worker consumes as
 * `scheduler_default`. Losing that attachment breaks no other test and fails no
 * static check — purging simply stops — so assert the registration itself.
 */
final class PurgeExpiredExportsCommandTest extends KernelTestCase
{
    public function test_purge_is_registered_hourly_on_the_default_schedule(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        // The container only defines this id once at least one task is attached
        // to the `default` schedule, so its absence is the failure, not a
        // broken test.
        self::assertTrue(
            $container->has('scheduler.provider.default'),
            'Nothing is attached to the `default` schedule.',
        );

        $provider = $container->get('scheduler.provider.default');
        self::assertInstanceOf(ScheduleProviderInterface::class, $provider);

        /** @var array<string, string> $triggerByCommand */
        $triggerByCommand = [];
        foreach ($provider->getSchedule()->getRecurringMessages() as $recurringMessage) {
            $trigger = $recurringMessage->getTrigger();
            $context = new MessageContext(
                'default',
                $recurringMessage->getId(),
                $trigger,
                new \DateTimeImmutable(),
            );

            foreach ($recurringMessage->getProvider()->getMessages($context) as $message) {
                if ($message instanceof RunCommandMessage) {
                    $triggerByCommand[$message->input] = (string) $trigger;
                }
            }
        }

        self::assertArrayHasKey('app:purge-expired-exports', $triggerByCommand);
        self::assertSame('30 * * * *', $triggerByCommand['app:purge-expired-exports']);
    }
}
