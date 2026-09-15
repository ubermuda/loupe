<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Doctrine\SearchLanguage;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Mcp\InboxSearchTool;
use App\Module\Inbox\Mcp\InboxWithdrawTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxSearchToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxSearchTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(InboxSearchTool::class);
        self::assertInstanceOf(InboxSearchTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-search-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)('anything');
    }

    /** The body is nullable, and a NULL body must not take the title out of the index. */
    public function test_a_title_word_finds_an_item_with_no_body(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-search-title'));
        $this->askQuestion('Which migration strategy?');
        $this->askQuestion('Unrelated');

        $result = ($this->tool)('migrations');

        self::assertSame(1, $result['total']);
        self::assertSame('Which migration strategy?', $result['items'][0]['title']);
    }

    public function test_a_word_that_lives_only_in_a_body_finds_the_item(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-search-body'));
        $this->askQuestion('Which one?', ['body' => 'The heartbeat interval decides it']);

        $result = ($this->tool)('heartbeat');

        self::assertSame(1, $result['total']);
        self::assertSame('Which one?', $result['items'][0]['title']);
    }

    public function test_a_closed_item_is_still_found(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-search-closed'));
        $asked = $this->askQuestion('Retire the mailpit sidecar?');
        $withdraw = self::getContainer()->get(InboxWithdrawTool::class);
        self::assertInstanceOf(InboxWithdrawTool::class, $withdraw);
        $withdraw($asked['items'][0]['itemId'], 'Settled in chat');

        $result = ($this->tool)('mailpit');

        self::assertSame(1, $result['total']);
        self::assertSame('withdrawn', $result['items'][0]['state']);
    }

    public function test_an_item_of_another_project_is_not_found(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-search-theirs'));
        $this->askQuestion('Retire the mailpit sidecar?');

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-search-mine'));
        $this->askQuestion('Something else');

        $result = ($this->tool)('mailpit');

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    /**
     * The French stemmer reads "tableaux" as "tableau", and the English one
     * does not, so a match proves the vector and the query are both French.
     */
    public function test_an_item_takes_its_project_s_search_language(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-search-language');
        $project->searchLanguage = SearchLanguage::French;
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        $asked = $this->askQuestion('Quels tableaux garder ?');

        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($asked['items'][0]['itemId']));
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(SearchLanguage::French, $item->searchLanguage);

        $result = ($this->tool)('tableau');

        self::assertSame(1, $result['total']);
        self::assertSame('Quels tableaux garder ?', $result['items'][0]['title']);
    }

    public function test_a_blank_query_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-search-blank'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('query: Pass a query to search for.');
        ($this->tool)('   ');
    }
}
