<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\View\CardHistoryEntry;
use App\Module\Bridge\Repository\WorkerRunRepository;
use Symfony\Component\Uid\Uuid;

final readonly class ShowCardHistoryHandler
{
    public const int PAGE_SIZE = 50;

    public function __construct(
        private CardEventRepository $cardEvents,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function __invoke(ShowCardHistoryCommand $command): CardHistoryView
    {
        $events = $this->cardEvents->findPageBeforeForCard($command->card, $command->beforeAt, $command->beforeId?->toRfc4122(), self::PAGE_SIZE + 1);
        $hasOlder = \count($events) > self::PAGE_SIZE;
        $events = \array_slice($events, 0, self::PAGE_SIZE);
        $last = $hasOlder ? array_last($events) : null;

        $runIds = array_values(array_filter(array_map(CardHistoryEntry::runIdOf(...), $events)));
        $existing = $this->workerRuns->findExistingIds($command->card->project, array_map(Uuid::fromString(...), $runIds));

        return new CardHistoryView(
            $command->card,
            $command->beforeId?->toRfc4122(),
            array_map(static function (CardEvent $event) use ($existing): CardHistoryEntry {
                $runId = CardHistoryEntry::runIdOf($event);

                return CardHistoryEntry::of($event, null !== $runId && \in_array(Uuid::fromString($runId)->toRfc4122(), $existing, true));
            }, $events),
            $last?->occurredAt->format(ShowCardHistoryCommand::CURSOR_FORMAT),
            $last?->id?->toRfc4122(),
        );
    }
}
