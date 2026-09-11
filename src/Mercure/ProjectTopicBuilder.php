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
}
