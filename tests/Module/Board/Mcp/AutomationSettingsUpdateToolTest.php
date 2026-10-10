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

        self::assertSame(['enabled' => false], ($this->tool)());
        self::assertFalse($this->stored($project->id)->enabled);

        self::assertSame(['enabled' => true], ($this->tool)(enabled: true));
        self::assertTrue($this->stored($project->id)->enabled);
    }

    public function test_a_project_with_no_stored_settings_starts_from_the_defaults(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('automation-update-defaults'));

        self::assertSame(['enabled' => true], ($this->tool)());
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
