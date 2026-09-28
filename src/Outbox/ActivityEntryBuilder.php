<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ActivityEntryBuilder
{
    /** @param iterable<ActivityLinkProviderInterface> $linkProviders */
    public function __construct(
        #[AutowireIterator('app.activity_link_provider')]
        private iterable $linkProviders,
    ) {
    }

    /**
     * @param list<OutboxEvent> $events
     *
     * @return list<ActivityEntry>
     */
    public function entriesFor(Project $project, array $events): array
    {
        $links = [];
        foreach ($this->linkProviders as $provider) {
            $links += $provider->linksFor($project, $events);
        }
        $entries = [];
        foreach ($events as $event) {
            $entries[] = new ActivityEntry($event, $links[(string) $event->id] ?? null);
        }

        return $entries;
    }
}
