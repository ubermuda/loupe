<?php

declare(strict_types=1);

namespace App\Module\SiteReview;

/**
 * Live push of submitted site reviews to a waiting agent, over Mercure.
 *
 * Everything reachable only through the hub hangs off this one flag: publishing
 * a submitted review, draining the outbox, and issuing the subscriber
 * credentials the bridge CLI uses. The rest of site review — leaving comments,
 * submitting them, and an agent pulling them with `site_review_get` — does not
 * involve Mercure and stays available with this off.
 *
 * The flag also carries an environment prerequisite (see
 * config/packages/ubermuda_feature_flags.yaml): without MERCURE_URL and
 * MERCURE_JWT_SECRET there is no hub to publish to, so it reads as off whatever
 * is stored and the admin shows it as unavailable rather than offering a switch
 * that cannot work.
 */
final class SiteReviewPush
{
    public const string FLAG = 'site_review.push.enabled';

    private function __construct()
    {
    }
}
