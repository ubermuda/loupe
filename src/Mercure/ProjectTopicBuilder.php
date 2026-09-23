<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the per-project Mercure topic string. The same builder serves both
 * sides of the channel, the publisher and the subscriber-JWT issuer, so the
 * topic matches whatever base URL is configured.
 *
 * The base is the app's own public URL. It is never dereferenced; it only
 * namespaces the topic so two instances cannot collide on a shared hub.
 */
final readonly class ProjectTopicBuilder
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    public function forProject(Uuid $projectId): string
    {
        return rtrim($this->appUrl, '/').'/projects/'.$projectId.'/events';
    }

    /**
     * The topic open boards listen on. A browser gets a token for this topic
     * only, so it never receives the agent payloads published on forProject().
     */
    public function forBoard(Uuid $projectId): string
    {
        return rtrim($this->appUrl, '/').'/projects/'.$projectId.'/board';
    }

    /** The project a forBoard() topic names, or null for any other string. */
    public function projectIdFromBoardTopic(string $topic): ?Uuid
    {
        return $this->projectIdFrom($topic, '/board');
    }

    /** The topic the worker run pages of a project listen on. The message names no run. */
    public function forWorkerRuns(Uuid $projectId): string
    {
        return rtrim($this->appUrl, '/').'/projects/'.$projectId.'/worker-runs';
    }

    /** The project a forWorkerRuns() topic names, or null for any other string. */
    public function projectIdFromWorkerRunsTopic(string $topic): ?Uuid
    {
        return $this->projectIdFrom($topic, '/worker-runs');
    }

    private function projectIdFrom(string $topic, string $suffix): ?Uuid
    {
        $prefix = rtrim($this->appUrl, '/').'/projects/';
        if (!str_starts_with($topic, $prefix) || !str_ends_with($topic, $suffix)) {
            return null;
        }

        $projectId = substr($topic, \strlen($prefix), -\strlen($suffix));

        return Uuid::isValid($projectId) ? Uuid::fromString($projectId) : null;
    }
}
