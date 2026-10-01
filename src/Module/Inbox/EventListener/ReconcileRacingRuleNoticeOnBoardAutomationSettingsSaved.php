<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Inbox\Service\RacingRuleNoticeReconciler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The sync switch decides whether a live rule on pull_request.behind races the app. */
#[AsEventListener]
final readonly class ReconcileRacingRuleNoticeOnBoardAutomationSettingsSaved
{
    public function __construct(
        private RacingRuleNoticeReconciler $reconciler,
    ) {
    }

    public function __invoke(BoardAutomationSettingsSaved $event): void
    {
        $this->reconciler->reconcile($event->project);
    }
}
