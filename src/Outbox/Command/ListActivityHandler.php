<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\ActivityEntry;
use App\Outbox\ActivityLinkProviderInterface;
use App\Outbox\Repository\OutboxEventRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ListActivityHandler
{
    private const int PAGE_SIZE = 100;

    /** @param iterable<ActivityLinkProviderInterface> $linkProviders */
    public function __construct(
        private OutboxEventRepository $outboxEvents,

        #[AutowireIterator('app.activity_link_provider')]
        private iterable $linkProviders,
    ) {
    }

    public function __invoke(ListActivityCommand $command): ListActivityView
    {
        $events = $this->outboxEvents->findRecentForProject($command->project, self::PAGE_SIZE);
        $links = [];
        foreach ($this->linkProviders as $provider) {
            $links += $provider->linksFor($command->project, $events);
        }
        $entries = [];
        foreach ($events as $event) {
            $entries[] = new ActivityEntry($event, $links[(string) $event->id] ?? null);
        }

        return new ListActivityView($command->project, $entries, self::PAGE_SIZE);
    }
}
