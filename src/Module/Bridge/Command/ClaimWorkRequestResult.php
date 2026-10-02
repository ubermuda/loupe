<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;

/** The claimed request, read fresh, or the reason the bridge did not get it. */
final readonly class ClaimWorkRequestResult
{
    public function __construct(
        public ?WorkRequest $request,
        public ?WorkRequestRefusal $refusal = null,
    ) {
    }
}
