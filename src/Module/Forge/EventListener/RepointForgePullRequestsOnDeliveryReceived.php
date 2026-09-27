<?php

declare(strict_types=1);

namespace App\Module\Forge\EventListener;

use App\Module\Forge\Event\ForgeDeliveryReceived;
use App\Module\Forge\ForgeEventType;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The stored path is the key a later refresh joins on, so a move nobody applied orphans the rows. */
#[AsEventListener]
final readonly class RepointForgePullRequestsOnDeliveryReceived
{
    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
    ) {
    }

    public function __invoke(ForgeDeliveryReceived $event): void
    {
        foreach ($event->deliveries as $delivery) {
            if (ForgeEventType::REPOSITORY_MOVED !== $delivery->type || null === $delivery->movedTo) {
                continue;
            }

            $this->forgePullRequests->repoint($event->projectId, $delivery->forge, $delivery->repository, $delivery->movedTo);
        }
    }
}
