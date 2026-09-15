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

    /**
     * The topic the inbox pill listens on. A browser gets a token for this topic
     * only, so it never receives the agent payloads published on forUser().
     */
    public function forInbox(Uuid $userId): string
    {
        return rtrim($this->appUrl, '/').'/users/'.$userId.'/inbox';
    }

    /** The user a forInbox() topic names, or null for any other string. */
    public function userIdFromInboxTopic(string $topic): ?Uuid
    {
        $prefix = rtrim($this->appUrl, '/').'/users/';
        if (!str_starts_with($topic, $prefix) || !str_ends_with($topic, '/inbox')) {
            return null;
        }

        $userId = substr($topic, \strlen($prefix), -\strlen('/inbox'));

        return Uuid::isValid($userId) ? Uuid::fromString($userId) : null;
    }
}
