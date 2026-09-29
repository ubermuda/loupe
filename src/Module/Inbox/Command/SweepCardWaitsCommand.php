<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

/** Queues a reconcile of every project whose card waits may have changed. It carries no data. */
final readonly class SweepCardWaitsCommand
{
}
