<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** How long a claim of a work request holds before another bridge can claim the request. */
final readonly class WorkRequestLease
{
    public function __construct(
        #[Autowire(param: 'app.bridge.work_request_lease_seconds')]
        private int $seconds,
    ) {
    }

    public function until(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('+'.$this->seconds.' seconds');
    }
}
