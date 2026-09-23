<?php

declare(strict_types=1);

namespace App\Module\Forge\Command;

use Symfony\Component\HttpFoundation\Request;

/**
 * The request is carried whole and unparsed. An adapter verifies the exact
 * bytes the forge signed, so anything that reads the body first destroys the
 * evidence the handler needs.
 */
final readonly class ReceiveForgeDeliveryCommand
{
    public function __construct(
        public string $forge,
        public Request $request,
    ) {
    }
}
