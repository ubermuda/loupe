<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Scheduler;

use App\Module\SiteReview\Scheduler\DrainSiteReviewOutboxTask;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The drain heals a stranded outbox only if something actually calls it, and
 * the wiring is entirely in attributes and a compiler pass — nothing in the
 * handler's own tests would notice the tick going missing.
 */
final class DrainSiteReviewOutboxTaskTest extends KernelTestCase
{
    public function test_the_drain_is_registered_on_the_default_schedule(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get('scheduler.provider.default');
        self::assertInstanceOf(ScheduleProviderInterface::class, $provider);

        $serviceIds = [];
        foreach ($provider->getSchedule()->getRecurringMessages() as $recurring) {
            $context = new MessageContext(
                'default',
                $recurring->getId(),
                $recurring->getTrigger(),
                new \DateTimeImmutable(),
            );
            foreach ($recurring->getMessages($context) as $message) {
                if ($message instanceof ServiceCallMessage) {
                    $serviceIds[] = $message->getServiceId();
                }
            }
        }

        self::assertContains(DrainSiteReviewOutboxTask::class, $serviceIds);
    }

    public function test_the_task_drains_when_invoked(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(DrainSiteReviewOutboxTask::class);
        self::assertInstanceOf(DrainSiteReviewOutboxTask::class, $task);

        // Nothing is owed on an empty outbox, so a clean return is the whole
        // assertion: the task resolves its handler and runs end to end.
        $task();
    }
}
