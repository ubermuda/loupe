<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the per-user Mercure topic that carries site-review events.
 *
 * The same builder is used on both sides — the publisher (SubmitBatchHandler)
 * and the subscriber-JWT issuer (StreamCredentialsController) — so the topic
 * string is guaranteed to match regardless of the configured base URL.
 */
final readonly class SiteReviewTopicBuilder
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    public function forUser(Uuid $userId): string
    {
        return rtrim($this->appUrl, '/').'/users/'.$userId.'/site-reviews';
    }
}
