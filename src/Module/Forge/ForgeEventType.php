<?php

declare(strict_types=1);

namespace App\Module\Forge;

/**
 * What a forge told Loupe, in Loupe's words.
 *
 * No name carries a forge, because a rule file and a bridge must not learn a
 * new event type for each forge an instance connects. A forge module maps its own
 * vocabulary onto these, and everything after that point is forge-blind.
 */
final class ForgeEventType
{
    public const string REVIEW_SUBMITTED = 'pull_request.review_submitted';
    public const string CHECKS_CONCLUDED = 'pull_request.checks_concluded';
    public const string MERGED = 'pull_request.merged';

    /** The repository path changed, so the key a delivery joins on is stale. */
    public const string REPOSITORY_MOVED = 'pull_request.repository_moved';

    private function __construct()
    {
    }
}
