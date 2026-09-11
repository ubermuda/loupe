<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardGetTool;
use App\Module\Board\Mcp\CardListTool;
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
        yield 'card_get' => [CardGetTool::NAME, CardGetTool::class];
        yield 'card_update' => [CardUpdateTool::NAME, CardUpdateTool::class];
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
        self::assertNotContains(CardUpdateTool::NAME, $names);
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
        self::assertLessThan($order[CardGetTool::NAME], $order[CardListTool::NAME]);
        self::assertLessThan($order[CardUpdateTool::NAME], $order[CardGetTool::NAME]);
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

    public function test_card_list_publishes_its_paging_and_summary_arguments(): void
    {
        $properties = $this->registry->getTool(CardListTool::NAME)->tool->inputSchema['properties'];

        self::assertSame(['type' => 'integer', 'description' => 'the 1-based page to read', 'default' => 1], $properties['page']);
        self::assertSame('integer', $properties['perPage']['type']);
        self::assertSame(CardListTool::DEFAULT_PER_PAGE, $properties['perPage']['default']);
        self::assertSame('boolean', $properties['full']['type']);
        self::assertFalse($properties['full']['default']);
    }

    /** The rename is a breaking change to the published surface, so both names are asserted. */
    public function test_the_board_tools_publish_reporter_and_no_longer_publish_origin(): void
    {
        foreach ([CardCreateTool::NAME, CardListTool::NAME] as $toolName) {
            $properties = $this->registry->getTool($toolName)->tool->inputSchema['properties'];

            self::assertArrayHasKey('reporter', $properties, $toolName);
            self::assertArrayNotHasKey('origin', $properties, $toolName);
            // Both tools take it as ?string, which publishes as a null/string union.
            self::assertContains('string', (array) $properties['reporter']['type'], $toolName);
        }
    }

    public function test_card_update_takes_no_reporter(): void
    {
        $schema = $this->registry->getTool(CardUpdateTool::NAME)->tool->inputSchema;

        self::assertArrayHasKey('status', $schema['properties']);
        self::assertArrayNotHasKey('reporter', $schema['properties']);
        self::assertSame(['cardId'], $schema['required']);
    }
}
