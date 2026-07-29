<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Scheduler;

use App\Module\SiteReview\Command\DrainOutboxCommand;
use App\Module\SiteReview\Command\DrainOutboxHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Five-minutely drain of the site-review outbox, so a transient hub outage
 * heals on its own. `app:drain-site-review-outbox` is the manual backstop.
 *
 * Cron, not `#[AsPeriodicTask]`: the periodic trigger counts down from worker
 * boot, and `--time-limit` recycles the worker, restarting the countdown.
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
