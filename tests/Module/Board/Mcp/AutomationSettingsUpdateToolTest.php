<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Mcp\AutomationSettingsUpdateTool;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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

    public function test_an_update_keeps_the_epic_settings_the_tool_does_not_take(): void
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
}
