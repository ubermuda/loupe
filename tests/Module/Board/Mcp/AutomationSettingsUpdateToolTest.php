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

    public function test_the_master_switch_changes_and_an_omitted_one_keeps_its_value(): void
    {
        $project = $this->makeProject('automation-update');
        $this->em->persist(new BoardAutomationSettings($project, enabled: false));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['enabled' => false, 'stuckDelayMinutes' => 15], ($this->tool)());
        self::assertFalse($this->stored($project->id)->enabled);

        self::assertSame(['enabled' => true, 'stuckDelayMinutes' => 15], ($this->tool)(enabled: true));
        self::assertTrue($this->stored($project->id)->enabled);
    }

    public function test_it_changes_the_stuck_delay(): void
    {
        $project = $this->makeProject('automation-update-stuck');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(stuckDelayMinutes: 45);

        self::assertSame(45, $result['stuckDelayMinutes']);
        self::assertSame(45, $this->stored($project->id)->stuckDelayMinutes);
    }

    public function test_it_names_the_range_of_a_stuck_delay_it_refuses(): void
    {
        $project = $this->makeProject('automation-update-stuck-range');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('stuckDelayMinutes: stuckDelayMinutes must be a whole number of minutes from 1 to 1440.');

        ($this->tool)(stuckDelayMinutes: 0);
    }

    public function test_a_project_with_no_stored_settings_starts_from_the_defaults(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('automation-update-defaults'));

        self::assertSame(['enabled' => true, 'stuckDelayMinutes' => 15], ($this->tool)());
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
}
