<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Entity\GitHubHook;
use Symfony\Component\HttpFoundation\Request;

/** The request is carried whole, because the signature covers the exact bytes GitHub sent. */
final readonly class ReceiveHookDeliveryCommand
{
    public function __construct(
        public GitHubHook $hook,
        public Request $request,
    ) {
    }
}
