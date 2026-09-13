<?php

declare(strict_types=1);

namespace App\Mercure\Command;

use App\Mercure\MercureSubscriptions;

/**
 * Renews the subscriber cookie of an open page before it reconnects. Another
 * page opened since may have replaced the cookie, and a token expires. The
 * cookie itself is written as the response leaves.
 */
final readonly class AuthorizeMercureTopicsHandler
{
    public function __construct(
        private MercureSubscriptions $subscriptions,
    ) {
    }

    /** @return list<string> the topics the new cookie allows */
    public function __invoke(AuthorizeMercureTopicsCommand $command): array
    {
        foreach ($command->topics as $topic) {
            $this->subscriptions->request($topic);
        }

        return $this->subscriptions->allowedTopics();
    }
}
