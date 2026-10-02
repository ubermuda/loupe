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

    /** The legacy topic an outbox row records. Nothing publishes on it. */
    public function forProject(Uuid $projectId): string
    {
        return rtrim($this->appUrl, '/').'/projects/'.$projectId.'/events';
    }

    /** The topic open boards listen on. A browser gets a token for this topic only. */
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

    /** The topic the Events pages of a project listen on. The message names no event. */
    public function forActivity(Uuid $projectId): string
    {
        return rtrim($this->appUrl, '/').'/projects/'.$projectId.'/activity';
    }

    /** The project a forActivity() topic names, or null for any other string. */
    public function projectIdFromActivityTopic(string $topic): ?Uuid
    {
        return $this->projectIdFrom($topic, '/activity');
    }

    /** The topic the review page of one document listens on. */
    public function forDocument(Uuid $projectId, Uuid $documentId): string
    {
        return rtrim($this->appUrl, '/').'/projects/'.$projectId.'/documents/'.$documentId;
    }

    /**
     * The project and the document a forDocument() topic names, or null for any other string.
     *
     * @return array{projectId: Uuid, documentId: Uuid}|null
     */
    public function idsFromDocumentTopic(string $topic): ?array
    {
        $prefix = rtrim($this->appUrl, '/').'/projects/';
        if (!str_starts_with($topic, $prefix)) {
            return null;
        }

        $segments = explode('/', substr($topic, \strlen($prefix)));
        if (3 !== \count($segments) || 'documents' !== $segments[1] || !Uuid::isValid($segments[0]) || !Uuid::isValid($segments[2])) {
            return null;
        }

        return ['projectId' => Uuid::fromString($segments[0]), 'documentId' => Uuid::fromString($segments[2])];
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
