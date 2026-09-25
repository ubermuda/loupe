<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\ListCardsHandler;
use App\Module\Board\Command\SearchBoardHandler;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Mcp\BoardColumnsTool;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardGetTool;
use App\Module\Board\Mcp\CardListTool;
use App\Module\Board\Mcp\CardRunCloseTool;
use App\Module\Board\Mcp\CardRunOpenTool;
use App\Module\Board\Mcp\CardSearchTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Project\Mcp\AdvertisedTools;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The board tools are reached only through the published schema, so their
 * names, their gate and the shape of pullRequestUrls are part of the contract.
 */
final class BoardToolRegistrationTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private Registry $registry;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        // Building the server is what runs the discovery loaders — the registry
        // service on its own has none and reads back empty.
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));

        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);
        $this->registry = $registry;
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function boardTools(): iterable
    {
        yield 'card_create' => [CardCreateTool::NAME, CardCreateTool::class];
        yield 'card_list' => [CardListTool::NAME, CardListTool::class];
        yield 'board_columns' => [BoardColumnsTool::NAME, BoardColumnsTool::class];
        yield 'card_search' => [CardSearchTool::NAME, CardSearchTool::class];
        yield 'card_get' => [CardGetTool::NAME, CardGetTool::class];
        yield 'card_update' => [CardUpdateTool::NAME, CardUpdateTool::class];
        yield 'card_run_open' => [CardRunOpenTool::NAME, CardRunOpenTool::class];
        yield 'card_run_close' => [CardRunCloseTool::NAME, CardRunCloseTool::class];
    }

    /** @param class-string $toolClass */
    #[DataProvider('boardTools')]
    public function test_every_board_tool_is_published(string $toolName, string $toolClass): void
    {
        self::assertTrue($this->registry->hasTool($toolName));
        self::assertInstanceOf($toolClass, self::getContainer()->get($toolClass));
    }

    public function test_the_board_tools_are_hidden_while_the_flag_is_off(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');

        // Guard: an empty roster would satisfy the absence assertions below.
        self::assertContains('document_create', $names);
        self::assertNotContains(CardCreateTool::NAME, $names);
        self::assertNotContains(BoardColumnsTool::NAME, $names);
        self::assertNotContains(CardSearchTool::NAME, $names);
        self::assertNotContains(CardUpdateTool::NAME, $names);
        self::assertNotContains(CardRunOpenTool::NAME, $names);
        self::assertNotContains(CardRunCloseTool::NAME, $names);
    }

    public function test_the_board_tools_are_advertised_once_the_flag_is_on(): void
    {
        $this->enableBoard();

        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');
        $order = array_flip($names);

        self::assertArrayHasKey(CardCreateTool::NAME, $order);
        self::assertLessThan($order[CardListTool::NAME], $order[CardCreateTool::NAME]);
        self::assertSame($order[CardListTool::NAME] + 1, $order[BoardColumnsTool::NAME]);
        self::assertLessThan($order[CardSearchTool::NAME], $order[BoardColumnsTool::NAME]);
        self::assertLessThan($order[CardGetTool::NAME], $order[CardSearchTool::NAME]);
        self::assertLessThan($order[CardUpdateTool::NAME], $order[CardGetTool::NAME]);
        self::assertSame($order[CardUpdateTool::NAME] + 1, $order[CardRunOpenTool::NAME]);
        self::assertSame($order[CardRunOpenTool::NAME] + 1, $order[CardRunCloseTool::NAME]);
    }

    public function test_the_run_tools_require_a_session_id(): void
    {
        $open = $this->registry->getTool(CardRunOpenTool::NAME)->tool->inputSchema;
        $close = $this->registry->getTool(CardRunCloseTool::NAME)->tool->inputSchema;

        self::assertSame(['sessionId', 'name'], $open['required']);
        self::assertSame(['sessionId'], $close['required']);
        self::assertSame(1, $open['properties']['number']['minimum']);
        self::assertSame(1, $close['properties']['number']['minimum']);
    }

    /** @param class-string $toolClass */
    #[DataProvider('boardTools')]
    public function test_every_board_tool_names_the_board_flag_as_its_gate(string $toolName, string $toolClass): void
    {
        $tool = self::getContainer()->get($toolClass);

        self::assertInstanceOf(FlagGatedToolInterface::class, $tool);
        self::assertSame($toolName, $tool->gatedToolName());
        self::assertSame(BoardInstallFlags::FLAG_BOARD_ENABLED, $tool->requiredFlag());
    }

    /**
     * `list<string>` would publish an array of anything, because the SDK parses
     * only the `T[]` and `array<T>` docblock spellings.
     */
    public function test_pull_request_urls_publish_as_an_array_of_strings(): void
    {
        foreach ([CardCreateTool::NAME, CardUpdateTool::NAME] as $toolName) {
            $schema = $this->registry->getTool($toolName)->tool->inputSchema;
            $property = $schema['properties']['pullRequestUrls'];

            self::assertSame(['type' => 'string'], $property['items'], $toolName);
            self::assertNotContains('pullRequestUrls', $schema['required'] ?? [], $toolName);
        }
    }

    /** An entry copied from card_get output carries more keys, so none may be refused. */
    public function test_related_cards_publish_as_optional_open_objects_with_a_kind_enum(): void
    {
        foreach ([CardCreateTool::NAME, CardUpdateTool::NAME] as $toolName) {
            $schema = $this->registry->getTool($toolName)->tool->inputSchema;
            $items = $schema['properties']['relatedCards']['items'];

            self::assertContains('array', (array) $schema['properties']['relatedCards']['type'], $toolName);
            self::assertSame(['cardId'], $items['required'], $toolName);
            self::assertSame(['relates-to', 'blocks', 'blocked-by'], $items['properties']['kind']['enum'], $toolName);
            self::assertArrayNotHasKey('additionalProperties', $items, $toolName);
            self::assertNotContains('relatedCards', $schema['required'] ?? [], $toolName);
        }

        // An explicit null keeps the links, the same as leaving the argument out.
        $update = $this->registry->getTool(CardUpdateTool::NAME)->tool->inputSchema['properties']['relatedCards'];
        self::assertContains('null', (array) $update['type']);
    }

    public function test_card_list_publishes_its_paging_and_summary_arguments(): void
    {
        $properties = $this->registry->getTool(CardListTool::NAME)->tool->inputSchema['properties'];

        self::assertSame(['type' => 'integer', 'description' => 'the 1-based page to read', 'default' => 1], $properties['page']);
        self::assertSame('integer', $properties['perPage']['type']);
        self::assertSame(ListCardsHandler::DEFAULT_PER_PAGE, $properties['perPage']['default']);
        self::assertSame('boolean', $properties['full']['type']);
        self::assertFalse($properties['full']['default']);
    }

    public function test_card_search_requires_a_query_and_publishes_its_paging(): void
    {
        $schema = $this->registry->getTool(CardSearchTool::NAME)->tool->inputSchema;

        self::assertSame(['query'], $schema['required']);
        self::assertSame('string', $schema['properties']['query']['type']);
        self::assertSame(['type' => 'integer', 'description' => 'the 1-based page to read', 'default' => 1], $schema['properties']['page']);
        self::assertSame(SearchBoardHandler::DEFAULT_PER_PAGE, $schema['properties']['perPage']['default']);
    }

    public function test_the_board_tools_publish_reporter(): void
    {
        foreach ([CardCreateTool::NAME, CardListTool::NAME] as $toolName) {
            $properties = $this->registry->getTool($toolName)->tool->inputSchema['properties'];

            self::assertArrayHasKey('reporter', $properties, $toolName);
            // Both tools take it as ?string, which publishes as a null/string union.
            self::assertContains('string', (array) $properties['reporter']['type'], $toolName);
            self::assertNotContains('reporter', $properties['required'] ?? [], $toolName);
        }
    }

    /**
     * Release 1 of the rename keeps the old name on the write tool alone, so an
     * agent that still sends it is not broken by this release. The filter is new,
     * so it never carried the old name.
     */
    public function test_only_card_create_still_publishes_the_deprecated_origin(): void
    {
        $create = $this->registry->getTool(CardCreateTool::NAME)->tool->inputSchema['properties'];
        $list = $this->registry->getTool(CardListTool::NAME)->tool->inputSchema['properties'];

        self::assertArrayHasKey('origin', $create);
        self::assertArrayNotHasKey('origin', $list);
    }

    public function test_card_update_takes_no_reporter(): void
    {
        $schema = $this->registry->getTool(CardUpdateTool::NAME)->tool->inputSchema;

        self::assertArrayHasKey('status', $schema['properties']);
        self::assertArrayNotHasKey('reporter', $schema['properties']);
        self::assertArrayNotHasKey('required', $schema);
    }

    public function test_card_get_and_card_update_take_a_card_id_or_a_number(): void
    {
        foreach ([CardGetTool::NAME, CardUpdateTool::NAME] as $toolName) {
            $schema = $this->registry->getTool($toolName)->tool->inputSchema;
            $number = $schema['properties']['number'];

            self::assertContains('integer', (array) $number['type'], $toolName);
            self::assertSame(1, $number['minimum'], $toolName);
            self::assertArrayHasKey('cardId', $schema['properties'], $toolName);
            self::assertNotContains('cardId', $schema['required'] ?? [], $toolName);
            self::assertNotContains('number', $schema['required'] ?? [], $toolName);
        }
    }
}
