<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Mcp\CardRunOpenTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ManagedCardToolTest extends KernelTestCase
{
    use McpTokenScenario;
    use WorkflowProjects;

    private int $cardNumber;

    protected function setUp(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('managed-tool');
        $this->bindLifecycle($project);
        $this->actAsMcpTokenBoundTo($project);

        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->cardNumber = $create(new CreateCardCommand(
            project: $project,
            title: 'Managed',
            body: 'Body',
            type: 'feature',
            column: $this->column($project, 'next'),
            reporter: CardReporter::Human,
        ))->number;
    }

    public function test_card_update_names_card_hold_when_the_card_is_managed(): void
    {
        $tool = self::getContainer()->get(CardUpdateTool::class);
        self::assertInstanceOf(CardUpdateTool::class, $tool);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(CardManaged::AGENT_MESSAGE);
        $tool(number: $this->cardNumber, status: 'in-progress');
    }

    public function test_card_run_open_names_card_hold_when_the_card_is_managed(): void
    {
        $tool = self::getContainer()->get(CardRunOpenTool::class);
        self::assertInstanceOf(CardRunOpenTool::class, $tool);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(CardManaged::AGENT_MESSAGE);
        $tool((string) Uuid::v7(), 'pair', number: $this->cardNumber, status: 'in-progress');
    }
}
