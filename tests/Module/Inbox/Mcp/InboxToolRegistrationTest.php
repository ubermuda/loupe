<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Mcp\InboxAskTool;
use App\Module\Inbox\Mcp\InboxGetTool;
use App\Module\Inbox\Mcp\InboxJoinTool;
use App\Module\Inbox\Mcp\InboxListTool;
use App\Module\Inbox\Mcp\InboxSearchTool;
use App\Module\Inbox\Mcp\InboxWithdrawTool;
use App\Module\Project\Mcp\AdvertisedTools;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The inbox tools are reached only through the published schema, so their names, gate and arguments are the contract. */
final class InboxToolRegistrationTest extends KernelTestCase
{
    use InboxToolScenario;

    private EntityManagerInterface $em;
    private Registry $registry;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        // Building the server runs the discovery loaders.
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));

        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);
        $this->registry = $registry;
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function inboxTools(): iterable
    {
        yield 'inbox_ask' => [InboxAskTool::NAME, InboxAskTool::class];
        yield 'inbox_search' => [InboxSearchTool::NAME, InboxSearchTool::class];
        yield 'inbox_join' => [InboxJoinTool::NAME, InboxJoinTool::class];
        yield 'inbox_list' => [InboxListTool::NAME, InboxListTool::class];
        yield 'inbox_get' => [InboxGetTool::NAME, InboxGetTool::class];
        yield 'inbox_withdraw' => [InboxWithdrawTool::NAME, InboxWithdrawTool::class];
    }

    /** @param class-string $toolClass */
    #[DataProvider('inboxTools')]
    public function test_every_inbox_tool_is_published_and_gated_on_the_inbox_flag(string $toolName, string $toolClass): void
    {
        self::assertTrue($this->registry->hasTool($toolName));

        $tool = self::getContainer()->get($toolClass);
        self::assertInstanceOf(FlagGatedToolInterface::class, $tool);
        self::assertSame($toolName, $tool->gatedToolName());
        self::assertSame(InboxInstallFlags::FLAG_INBOX_ENABLED, $tool->requiredFlag());
    }

    public function test_the_inbox_tools_are_hidden_while_the_flag_is_off(): void
    {
        $names = array_column($this->advertised()->enabled(), 'name');

        // Guard: an empty roster would satisfy the absence assertions below.
        self::assertContains('document_create', $names);
        foreach (self::inboxTools() as [$toolName]) {
            self::assertNotContains($toolName, $names);
        }
    }

    public function test_the_inbox_tools_are_advertised_in_order_once_the_flag_is_on(): void
    {
        $this->enableInbox();

        $names = array_column($this->advertised()->enabled(), 'name');
        $inbox = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'inbox_')));

        self::assertSame([InboxAskTool::NAME, InboxSearchTool::NAME, InboxJoinTool::NAME, InboxListTool::NAME, InboxGetTool::NAME, InboxWithdrawTool::NAME], $inbox);
    }

    public function test_inbox_ask_publishes_its_items_as_a_list_of_objects(): void
    {
        $schema = $this->registry->getTool(InboxAskTool::NAME)->tool->inputSchema;

        self::assertSame(['sessionId', 'items'], $schema['required']);
        self::assertSame('array', $schema['properties']['items']['type']);
        self::assertSame('object', $schema['properties']['items']['items']['type']);
        self::assertSame(['kind', 'title'], $schema['properties']['items']['items']['required']);
        self::assertSame(['question', 'todo'], $schema['properties']['items']['items']['properties']['kind']['enum']);
        self::assertSame(['type' => 'string'], $schema['properties']['items']['items']['properties']['cardIds']['items']);
    }

    public function test_inbox_join_requires_the_item_and_the_session(): void
    {
        $schema = $this->registry->getTool(InboxJoinTool::NAME)->tool->inputSchema;

        self::assertSame(['itemId', 'sessionId'], $schema['required']);
        self::assertArrayHasKey('bridgeId', $schema['properties']);
    }

    public function test_inbox_list_publishes_its_filters_and_no_argument_is_required(): void
    {
        $schema = $this->registry->getTool(InboxListTool::NAME)->tool->inputSchema;

        foreach (['state', 'askId', 'sessionId', 'cardId', 'documentId', 'page', 'perPage'] as $argument) {
            self::assertArrayHasKey($argument, $schema['properties'], $argument);
        }
        self::assertSame([], $schema['required'] ?? []);
    }

    public function test_inbox_withdraw_requires_a_reason(): void
    {
        $schema = $this->registry->getTool(InboxWithdrawTool::NAME)->tool->inputSchema;

        self::assertSame(['itemId', 'reason'], $schema['required']);
    }

    private function advertised(): AdvertisedTools
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        return $advertised;
    }
}
