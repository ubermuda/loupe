<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Mcp\AutomationSettingsUpdateTool;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class AutomationSettingsUpdateToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private AutomationSettingsUpdateTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(AutomationSettingsUpdateTool::class);
        self::assertInstanceOf(AutomationSettingsUpdateTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_given_settings_change_and_omitted_ones_keep_their_value(): void
    {
        $project = $this->makeProject('automation-update');
        $this->em->persist(new BoardAutomationSettings($project, enabled: false, changeBase: true));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(commentOnFixQueued: true, mergePullRequests: true);

        $expected = [
            'enabled' => false,
            'commentOnFixQueued' => true,
            'commentOnStaleApproval' => false,
            'syncBehind' => false,
            'mergePullRequests' => true,
            'changeBase' => true,
            'epicDraftSwitch' => false,
            'closeEpicPullRequests' => false,
            'openEpicPullRequests' => false,
            'postWidgetReviews' => false,
            'siteReviewCheck' => false,
            'epicBranchPattern' => 'epic/{number}',
            'stuckDelayMinutes' => 15,
        ];
        self::assertSame($expected, $result);

        $this->em->clear();
        $repository = self::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $repository);
        $stored = $repository->findOneBy(['project' => $project->id]);
        self::assertInstanceOf(BoardAutomationSettings::class, $stored);
        self::assertFalse($stored->enabled);
        self::assertTrue($stored->commentOnFixQueued);
        self::assertTrue($stored->mergePullRequests);
        self::assertTrue($stored->changeBase);
    }

    public function test_it_changes_the_stuck_delay(): void
    {
        $project = $this->makeProject('automation-update-stuck');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(stuckDelayMinutes: 45);

        self::assertSame(45, $result['stuckDelayMinutes']);
    }

    public function test_it_names_the_range_of_a_stuck_delay_it_refuses(): void
    {
        $project = $this->makeProject('automation-update-stuck-range');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('stuckDelayMinutes: stuckDelayMinutes must be a whole number of minutes from 1 to 1440.');

        ($this->tool)(stuckDelayMinutes: 0);
    }

    public function test_an_update_keeps_the_epic_settings_it_omits(): void
    {
        $project = $this->makeProject('automation-update-epic');
        $this->em->persist(new BoardAutomationSettings($project, openEpicPullRequests: true, epicBranchPattern: 'feature/{number}'));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        ($this->tool)(syncBehind: true);

        $this->em->clear();
        $repository = self::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $repository);
        $stored = $repository->findOneBy(['project' => $project->id]);
        self::assertInstanceOf(BoardAutomationSettings::class, $stored);
        self::assertTrue($stored->openEpicPullRequests);
        self::assertSame('feature/{number}', $stored->epicBranchPattern);
    }

    public function test_the_widget_verdict_opt_ins_are_set_and_kept_when_omitted(): void
    {
        $project = $this->makeProject('automation-update-verdict');
        $this->em->persist(new BoardAutomationSettings($project));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(postWidgetReviews: true, siteReviewCheck: true);
        self::assertTrue($result['postWidgetReviews']);
        self::assertTrue($result['siteReviewCheck']);

        $result = ($this->tool)(syncBehind: true);
        self::assertTrue($result['postWidgetReviews']);
        self::assertTrue($result['siteReviewCheck']);
        $stored = $this->stored($project->id);
        self::assertTrue($stored->postWidgetReviews);
        self::assertTrue($stored->siteReviewCheck);
    }

    public function test_it_sets_both_epic_settings_and_turning_the_opening_on_rearms_it(): void
    {
        $project = $this->makeProject('automation-update-epic-set');
        $this->em->persist(new BoardAutomationSettings($project));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        $saved = $this->savedEvents();

        $result = ($this->tool)(openEpicPullRequests: true, epicBranchPattern: '  feature/epic-{number}  ');

        self::assertTrue($result['openEpicPullRequests']);
        self::assertSame('feature/epic-{number}', $result['epicBranchPattern']);
        $stored = $this->stored($project->id);
        self::assertTrue($stored->openEpicPullRequests);
        self::assertSame('feature/epic-{number}', $stored->epicBranchPattern);
        self::assertCount(1, $saved->events);
        self::assertTrue($saved->events[0]->openEpicTurnedOn);
    }

    public function test_an_empty_pattern_turns_epic_branches_off(): void
    {
        $project = $this->makeProject('automation-update-epic-clear');
        $this->em->persist(new BoardAutomationSettings($project, epicBranchPattern: 'feature/{number}'));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(epicBranchPattern: '');

        self::assertNull($result['epicBranchPattern']);
        self::assertNull($this->stored($project->id)->epicBranchPattern);
    }

    public function test_a_pattern_that_is_no_branch_name_with_one_number_is_refused_and_nothing_is_saved(): void
    {
        $project = $this->makeProject('automation-update-epic-refused');
        $this->em->persist(new BoardAutomationSettings($project, epicBranchPattern: 'feature/{number}'));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        $saved = $this->savedEvents();

        try {
            ($this->tool)(syncBehind: true, epicBranchPattern: 'epic/{number}/{number}');
            self::fail('The tool accepted a pattern with the placeholder twice.');
        } catch (ToolCallException $e) {
            self::assertStringStartsWith('epicBranchPattern: ', $e->getMessage());
            self::assertStringContainsString('exactly once', $e->getMessage());
        }

        $stored = $this->stored($project->id);
        self::assertSame('feature/{number}', $stored->epicBranchPattern);
        self::assertFalse($stored->syncBehind);
        self::assertSame([], $saved->events);
    }

    public function test_a_project_with_no_stored_settings_starts_from_the_defaults(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('automation-update-defaults'));

        $result = ($this->tool)(epicDraftSwitch: true);

        self::assertTrue($result['enabled']);
        self::assertTrue($result['epicDraftSwitch']);
        self::assertFalse($result['closeEpicPullRequests']);
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $project = $this->makeProject('automation-update-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)(enabled: false);
    }

    private function stored(?Uuid $projectId): BoardAutomationSettings
    {
        $this->em->clear();
        $repository = self::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $repository);
        $stored = $repository->findOneBy(['project' => $projectId]);
        self::assertInstanceOf(BoardAutomationSettings::class, $stored);

        return $stored;
    }

    /** @return object{events: list<BoardAutomationSettingsSaved>} */
    private function savedEvents(): object
    {
        $saved = new class {
            /** @var list<BoardAutomationSettingsSaved> */
            public array $events = [];
        };
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BoardAutomationSettingsSaved::class, static function (BoardAutomationSettingsSaved $event) use ($saved): void {
            $saved->events[] = $event;
        });

        return $saved;
    }
}
