<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Bridge\Service\BridgeUpgradeRequired;
use App\Module\Bridge\Service\EventStreamGate;
use App\Outbox\AgentPush;
use App\Outbox\Repository\OutboxEventRepository;

final readonly class ShowEventReplayHandler
{
    private const int PAGE_SIZE = 200;

    private const int OVERLAP_LIMIT = 200;

    /**
     * A sequence is taken at insert and a row becomes visible at commit, so a
     * row can commit after a higher one was already delivered. Rows at or below
     * the cursor that are this close to it in time are sent again.
     */
    private const string OVERLAP_WINDOW = '-2 minutes';

    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private EventStreamGate $gate,
    ) {
    }

    /** @throws BridgeUpgradeRequired */
    public function __invoke(ShowEventReplayCommand $command): ShowEventReplayView
    {
        $this->gate->admit($command->user, $command->bridgeId);

        $newer = $this->outboxEvents->findOwnedAbove($command->user, AgentPush::BRIDGE_TYPES, $command->after, self::PAGE_SIZE + 1);
        $hasMore = \count($newer) > self::PAGE_SIZE;

        $anchor = $this->outboxEvents->createdAtOfHighestAtOrBelow($command->after);
        $overlap = null === $anchor ? [] : $this->outboxEvents->findOwnedAtOrBelowSince(
            $command->user,
            AgentPush::BRIDGE_TYPES,
            $command->after,
            $anchor->modify(self::OVERLAP_WINDOW),
            self::OVERLAP_LIMIT,
        );

        return new ShowEventReplayView(
            events: [...array_reverse($overlap), ...\array_slice($newer, 0, self::PAGE_SIZE)],
            hasMore: $hasMore,
        );
    }
}
