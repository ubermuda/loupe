<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Container\ContainerInterface;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Reads back what `#[AsCronTask]` actually registered on a schedule.
 *
 * A scheduled job's wiring lives entirely in an attribute and a compiler pass,
 * so nothing in the task's own tests notices the tick going missing. Every task
 * therefore gets a test asserting it appears here with the expression it
 * declares.
 */
final class ScheduledTasks
{
    /**
     * Cron expression per task service id on the named schedule.
     *
     * @return array<string, string>
     */
    public static function cronExpressions(ContainerInterface $container, string $schedule = 'default'): array
    {
        $provider = $container->get('scheduler.provider.'.$schedule);
        \assert($provider instanceof ScheduleProviderInterface);

        $expressions = [];

        foreach ($provider->getSchedule()->getRecurringMessages() as $recurring) {
            $trigger = $recurring->getTrigger();
            $context = new MessageContext($schedule, $recurring->getId(), $trigger, new \DateTimeImmutable());

            foreach ($recurring->getMessages($context) as $message) {
                if ($message instanceof ServiceCallMessage) {
                    $expressions[$message->getServiceId()] = (string) $trigger;
                }
            }
        }

        return $expressions;
    }
}
