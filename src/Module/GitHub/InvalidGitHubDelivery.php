<?php

declare(strict_types=1);

namespace App\Module\GitHub;

/** The delivery did not come from GitHub, or not in a shape GitHub sends. Nothing in it is trustworthy. */
final class InvalidGitHubDelivery extends \RuntimeException
{
    public const string NO_SECRET = 'no_secret';
    public const string BAD_SIGNATURE = 'bad_signature';
    public const string BAD_PAYLOAD = 'bad_payload';

    /** @param self::NO_SECRET|self::BAD_SIGNATURE|self::BAD_PAYLOAD $reason a short code, stored on the hook it failed for */
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
