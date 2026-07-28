<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Scheduler;

use App\Module\SiteReview\Command\DrainOutboxCommand;
use App\Module\SiteReview\Command\DrainOutboxHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Five-minutely drain of the site-review outbox, so a transient hub outage
 * heals without anyone noticing it happened.
 *
 * Registered with `#[AsCronTask]` rather than a `ScheduleProviderInterface`:
 * only one provider may claim a schedule name and `default` is already taken,
 * while a second schedule would mean a second transport in every
 * `messenger:consume` command — including the production one, which lives in
 * deploy config outside this repository. Tagged tasks decorate the existing
 * provider instead, so this rides the `scheduler_default` transport the worker
 * already consumes.
 *
 * A cron trigger, not `every('5 minutes')`: the periodic trigger counts down
 * from worker boot, so a worker recycled by `--time-limit` restarts the
 * countdown and the tick can starve.
 */
#[AsCronTask('*/5 * * * *')]
final readonly class DrainSiteReviewOutboxTask
{
    public function __construct(
        private DrainOutboxHandler $drainOutbox,
    ) {
    }

    public function __invoke(): void
    {
        ($this->drainOutbox)(new DrainOutboxCommand());
    }
}
