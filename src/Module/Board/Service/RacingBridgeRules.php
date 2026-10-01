<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\RacingBridgeRuleView;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Forge\ForgeEventType;
use App\Module\Project\Entity\Project;

/** The live bridge rules on a pull request that is behind, while the app syncs such a pull request itself. */
final readonly class RacingBridgeRules
{
    public function __construct(
        private BoardAutomation $boardAutomation,
    ) {
    }

    /**
     * Takes the reports of the project the caller already loaded, so a page asks for them once.
     *
     * @param list<BridgeRuleReport> $reports
     *
     * @return list<RacingBridgeRuleView>
     */
    public function forProject(Project $project, array $reports): array
    {
        if (!$this->appSyncsBehind($project)) {
            return [];
        }

        $racing = [];
        foreach ($reports as $report) {
            foreach ($report->rules as $rule) {
                if (self::racesSync($rule)) {
                    $racing[] = new RacingBridgeRuleView($rule['name'], $report->receivedAt, (string) $report->bridgeId);
                }
            }
        }

        return $racing;
    }

    public function appSyncsBehind(Project $project): bool
    {
        $settings = $this->boardAutomation->settingsOf($project);

        return $settings->enabled && $settings->syncBehind;
    }

    /** @param array{on: string, state: string} $rule */
    public static function racesSync(array $rule): bool
    {
        return ForgeEventType::BEHIND === $rule['on'] && BridgeRuleReport::STATE_LIVE === $rule['state'];
    }
}
