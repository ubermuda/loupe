<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Mcp\CardRunCloseTool;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\Mcp\BridgeCommandCancelTool;
use App\Module\Bridge\Mcp\BridgeListTool;
use App\Module\Bridge\Mcp\CardHoldTool;
use App\Module\Bridge\Mcp\CardReleaseTool;
use App\Module\Bridge\Mcp\WorkerRunGetTool;
use App\Module\Bridge\Mcp\WorkerRunListTool;
use App\Module\Bridge\Mcp\WorkerRunResumeTool;
use App\Module\Bridge\Mcp\WorkerRunStopTool;
use App\Module\Project\Mcp\AdvertisedTools;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * The worker tools are reached only through the published schema, so their
 * names, their order and the shape of their list arguments are part of the contract.
 */
final class BridgeToolRegistrationTest extends KernelTestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        self::bootKernel();

        // Building the server runs the discovery loaders.
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));

        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);
        $this->registry = $registry;
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function bridgeTools(): iterable
    {
        yield 'worker_run_list' => [WorkerRunListTool::NAME, WorkerRunListTool::class];
        yield 'worker_run_get' => [WorkerRunGetTool::NAME, WorkerRunGetTool::class];
        yield 'bridge_list' => [BridgeListTool::NAME, BridgeListTool::class];
        yield 'worker_run_resume' => [WorkerRunResumeTool::NAME, WorkerRunResumeTool::class];
        yield 'worker_run_stop' => [WorkerRunStopTool::NAME, WorkerRunStopTool::class];
        yield 'card_hold' => [CardHoldTool::NAME, CardHoldTool::class];
        yield 'card_release' => [CardReleaseTool::NAME, CardReleaseTool::class];
        yield 'bridge_command_cancel' => [BridgeCommandCancelTool::NAME, BridgeCommandCancelTool::class];
    }

    /**
     * No flag gates them: the token acts as the project owner, who reaches the
     * same controls on the web page.
     *
     * @param class-string $toolClass
     */
    #[DataProvider('bridgeTools')]
    public function test_every_bridge_tool_is_published_with_no_flag(string $toolName, string $toolClass): void
    {
        self::assertTrue($this->registry->hasTool($toolName));
        $tool = self::getContainer()->get($toolClass);
        self::assertInstanceOf($toolClass, $tool);
        self::assertNotInstanceOf(FlagGatedToolInterface::class, $tool);
    }

    /** @return iterable<string, array{string}> */
    public static function bridgeToolNames(): iterable
    {
        foreach (self::bridgeTools() as $name => [$toolName]) {
            yield $name => [$toolName];
        }
    }

    #[DataProvider('bridgeToolNames')]
    public function test_every_bridge_tool_has_a_connect_page_description(string $toolName): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $key = 'project.connect.tool.'.$toolName;

        self::assertNotSame($key, $translator->trans($key));
    }

    public function test_the_bridge_tools_are_advertised_after_the_board_run_tools(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();

        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');
        $start = array_search(WorkerRunListTool::NAME, $names, true);
        self::assertIsInt($start);

        self::assertSame(
            [WorkerRunListTool::NAME, WorkerRunGetTool::NAME, BridgeListTool::NAME, WorkerRunResumeTool::NAME, WorkerRunStopTool::NAME, CardHoldTool::NAME, CardReleaseTool::NAME, BridgeCommandCancelTool::NAME],
            \array_slice($names, $start, 8),
        );
        $close = array_search(CardRunCloseTool::NAME, $names, true);
        self::assertIsInt($close);
        self::assertSame($close + 1, $start);
    }

    /** `list<string>` would publish an array of anything, because the SDK parses only `T[]` and `array<T>`. */
    public function test_worker_run_resume_publishes_a_bounded_list_of_run_ids(): void
    {
        $schema = $this->registry->getTool(WorkerRunResumeTool::NAME)->tool->inputSchema;
        $runIds = $schema['properties']['runIds'];

        self::assertSame(['runIds'], $schema['required']);
        self::assertSame(['type' => 'string'], $runIds['items']);
        self::assertSame(1, $runIds['minItems']);
        self::assertSame(WorkerRunResumeTool::MAX_RUNS, $runIds['maxItems']);
    }

    public function test_worker_run_list_publishes_its_filters_and_paging(): void
    {
        $schema = $this->registry->getTool(WorkerRunListTool::NAME)->tool->inputSchema;
        $properties = $schema['properties'];

        foreach (['states', 'cardNumber', 'rule', 'bridgeId', 'endedAfter', 'endedBefore', 'search', 'page', 'perPage'] as $argument) {
            self::assertArrayHasKey($argument, $properties, $argument);
        }
        self::assertSame(['type' => 'string'], $properties['states']['items']);
        self::assertSame(1, $properties['cardNumber']['minimum']);
        self::assertSame(ListWorkerRunsHandler::PER_PAGE, $properties['perPage']['default']);
        self::assertSame([], $schema['required'] ?? []);
    }

    public function test_the_card_hold_tools_take_a_card_id_or_a_number(): void
    {
        foreach ([CardHoldTool::NAME, CardReleaseTool::NAME] as $toolName) {
            $schema = $this->registry->getTool($toolName)->tool->inputSchema;

            self::assertSame([], $schema['required'] ?? [], $toolName);
            self::assertArrayHasKey('cardId', $schema['properties'], $toolName);
            self::assertSame(1, $schema['properties']['number']['minimum'], $toolName);
        }
    }

    public function test_the_single_run_tools_require_a_run_id(): void
    {
        foreach ([WorkerRunGetTool::NAME, WorkerRunStopTool::NAME, BridgeCommandCancelTool::NAME] as $toolName) {
            self::assertSame(['runId'], $this->registry->getTool($toolName)->tool->inputSchema['required'], $toolName);
        }
    }
}
