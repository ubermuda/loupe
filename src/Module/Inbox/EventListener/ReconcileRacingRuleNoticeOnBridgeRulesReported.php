<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\BridgeRulesReported;
use App\Module\Inbox\Service\RacingRuleNoticeReconciler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ReconcileRacingRuleNoticeOnBridgeRulesReported
{
    public function __construct(
        private RacingRuleNoticeReconciler $reconciler,
    ) {
    }

    public function __invoke(BridgeRulesReported $event): void
    {
        $this->reconciler->reconcile($event->project);
    }
}
