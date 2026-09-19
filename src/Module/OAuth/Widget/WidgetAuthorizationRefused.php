<?php

declare(strict_types=1);

namespace App\Module\OAuth\Widget;

use Symfony\Component\HttpFoundation\Response;

/**
 * A widget sign-in the popup must refuse on the spot. It never redirects,
 * because the callback has no checked origin to post the answer to.
 */
final class WidgetAuthorizationRefused extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonKey,
        public readonly int $status = Response::HTTP_FORBIDDEN,
    ) {
        parent::__construct($reasonKey);
    }
}
