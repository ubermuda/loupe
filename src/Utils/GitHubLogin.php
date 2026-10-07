<?php

declare(strict_types=1);

namespace App\Utils;

/** The shape GitHub allows for a user login. */
final class GitHubLogin
{
    public const int MAX_LENGTH = 39;

    /** Letters, digits and single hyphens, with no hyphen at either end. */
    public const string PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/D';
}
