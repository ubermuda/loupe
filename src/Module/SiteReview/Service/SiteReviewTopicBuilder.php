<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Builds Mercure topic strings for site-review events.
 *
 * Topics are per-site. The same builder is used on both sides of the channel —
 * the publisher (SubmitReviewHandler) and the subscriber-JWT issuer
 * (StreamCredentialsController) — so the topic string is guaranteed to match
 * regardless of the configured base URL.
 */
final readonly class SiteReviewTopicBuilder
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    public function forSite(Uuid $siteId): string
    {
        return rtrim($this->appUrl, '/').'/sites/'.$siteId.'/site-reviews';
    }
}
