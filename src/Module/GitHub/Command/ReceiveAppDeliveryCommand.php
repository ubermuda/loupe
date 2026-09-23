<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use Symfony\Component\HttpFoundation\Request;

/** The request is carried whole, because the signature covers the exact bytes GitHub sent. */
final readonly class ReceiveAppDeliveryCommand
{
    public function __construct(
        public Request $request,
    ) {
    }
}
