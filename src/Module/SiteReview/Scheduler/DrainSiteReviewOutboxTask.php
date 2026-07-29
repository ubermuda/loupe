<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Scheduler;

use App\Module\SiteReview\Command\DrainOutboxCommand;
use App\Module\SiteReview\Command\DrainOutboxHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Five-minutely drain of the site-review outbox, so a transient hub outage
 * heals without anyone noticing it happened. `app:drain-site-review-outbox` is
 * the manual backstop.
 *
 * Scheduled with `#[AsCronTask]`, which is how every scheduled job in this
 * codebase is registered. Tagged tasks all compose onto the one `default`
 * schedule and therefore onto the single `scheduler_default` transport the
 * worker already consumes; a `ScheduleProviderInterface` would not, because
 * only one provider may claim a schedule name — a second module wanting a job
 * would have to either edit the first module's provider or claim a new
 * schedule name, and a new schedule name means a new transport in every
 * `messenger:consume` command, including the production one.
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
