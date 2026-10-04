<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

/** Thrown when a bridge cannot follow the events, because it runs no work requests. The API controllers map this to a 426. */
final class BridgeUpgradeRequired extends \DomainException
{
}
