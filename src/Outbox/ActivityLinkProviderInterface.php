<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.activity_link_provider')]
interface ActivityLinkProviderInterface
{
    /**
     * @param list<OutboxEvent> $events
     *
     * @return array<string, ActivityLink>
     */
    public function linksFor(Project $project, array $events): array;
}
