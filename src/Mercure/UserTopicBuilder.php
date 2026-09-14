<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the per-user Mercure topic. The outbox drain publishes every event of
 * a project on its owner's topic too, so one subscriber JWT with one topic
 * follows every project of a user, however many there are.
 */
final readonly class UserTopicBuilder
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    public function forUser(Uuid $userId): string
    {
        return rtrim($this->appUrl, '/').'/users/'.$userId.'/events';
    }
}
