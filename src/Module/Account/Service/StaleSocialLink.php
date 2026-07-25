<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

/**
 * The account a pending social link points at no longer satisfies the conditions
 * that produced that link — it was deleted, lost its password, or changed its
 * email since the OAuth callback. The provider therefore no longer proves
 * anything about it, so the link must not be completed.
 */
final class StaleSocialLink extends \RuntimeException
{
}
