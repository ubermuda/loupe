<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Event\BridgeNameChanged;
use App\Module\Inbox\Service\RacingRuleNoticeReconciler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The racing rule notice names each bridge, so a new name rewrites it. */
#[AsEventListener]
final readonly class ReconcileRacingRuleNoticeOnBridgeNameChanged
{
    public function __construct(
        private RacingRuleNoticeReconciler $reconciler,
    ) {
    }

    public function __invoke(BridgeNameChanged $event): void
    {
        foreach ($event->projects as $project) {
            $this->reconciler->reconcile($project);
        }
    }
}
