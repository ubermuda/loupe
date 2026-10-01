<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Command\RacingBridgeRuleView;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Board\Service\RacingBridgeRules;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RacingBridgeRulesTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private RacingBridgeRules $racingRules;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $racingRules = self::getContainer()->get(RacingBridgeRules::class);
        self::assertInstanceOf(RacingBridgeRules::class, $racingRules);
        $this->racingRules = $racingRules;
    }

    public function test_a_live_behind_rule_races_while_the_app_syncs(): void
    {
        $project = $this->makeProject('racing-on');
        $this->settings($project, enabled: true, syncBehind: true);
        $bridgeId = $this->report($project, new \DateTimeImmutable('2026-09-30 09:00'));

        $racing = $this->racing($project);

        self::assertEquals([new RacingBridgeRuleView('merge-behind', new \DateTimeImmutable('2026-09-30 09:00'), (string) $bridgeId)], $racing);
    }

    public function test_no_rule_races_while_sync_is_off(): void
    {
        $project = $this->makeProject('racing-sync-off');
        $this->settings($project, enabled: true, syncBehind: false);
        $this->report($project);

        self::assertSame([], $this->racing($project));
    }

    public function test_no_rule_races_while_the_automation_is_off(): void
    {
        $project = $this->makeProject('racing-automation-off');
        $this->settings($project, enabled: false, syncBehind: true);
        $this->report($project);

        self::assertSame([], $this->racing($project));
    }

    public function test_no_rule_races_for_a_project_that_saved_no_settings(): void
    {
        $project = $this->makeProject('racing-defaults');
        $this->report($project);

        self::assertSame([], $this->racing($project));
    }

    public function test_a_dead_behind_rule_and_a_rule_on_another_event_do_not_race(): void
    {
        $project = $this->makeProject('racing-only-live');
        $this->settings($project, enabled: true, syncBehind: true);
        $this->report($project);

        $names = array_map(static fn (RacingBridgeRuleView $rule): string => $rule->name, $this->racing($project));

        self::assertSame(['merge-behind'], $names);
    }

    /** @return list<RacingBridgeRuleView> */
    private function racing(Project $project): array
    {
        $reports = self::getContainer()->get(BridgeRuleReportRepository::class);
        self::assertInstanceOf(BridgeRuleReportRepository::class, $reports);

        return $this->racingRules->forProject($project, $reports->findForProject($project));
    }

    private function settings(Project $project, bool $enabled, bool $syncBehind): void
    {
        $this->em->persist(new BoardAutomationSettings($project, enabled: $enabled, syncBehind: $syncBehind));
        $this->em->flush();
    }

    private function report(Project $project, \DateTimeImmutable $receivedAt = new \DateTimeImmutable()): Uuid
    {
        $bridgeId = Uuid::v4();
        $this->em->persist(new BridgeRuleReport($project, $bridgeId, [
            ['name' => 'merge-behind', 'on' => 'pull_request.behind', 'columns' => [], 'state' => BridgeRuleReport::STATE_LIVE, 'reason' => null],
            ['name' => 'stale-behind', 'on' => 'pull_request.behind', 'columns' => [], 'state' => BridgeRuleReport::STATE_DEAD, 'reason' => 'unknown_event'],
            ['name' => 'merge-ready', 'on' => 'pull_request.ready_to_merge', 'columns' => [], 'state' => BridgeRuleReport::STATE_LIVE, 'reason' => null],
            ['name' => 'plan', 'on' => 'board.card_moved', 'columns' => ['next'], 'state' => BridgeRuleReport::STATE_LIVE, 'reason' => null],
        ], $receivedAt));
        $this->em->flush();

        return $bridgeId;
    }
}
