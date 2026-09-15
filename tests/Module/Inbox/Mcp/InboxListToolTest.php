<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Mcp\InboxListTool;
use App\Module\Inbox\Mcp\InboxWithdrawTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxListToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxListTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(InboxListTool::class);
        self::assertInstanceOf(InboxListTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-list-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)();
    }

    public function test_the_items_of_the_project_read_newest_first_and_another_project_s_are_absent(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-list-theirs'));
        $this->askQuestion('Not yours');

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-list-mine'));
        $this->askQuestion('First');
        $this->askQuestion('Second');

        $result = ($this->tool)();

        self::assertSame(2, $result['total']);
        self::assertFalse($result['hasMore']);
        self::assertSame(['Second', 'First'], array_column($result['items'], 'title'));
    }

    public function test_the_filters_narrow_the_page(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-list-filters');
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        $sessionId = (string) Uuid::v4();

        $linked = $this->askQuestion('Linked', ['cardIds' => [(string) $card->id], 'documentIds' => [(string) $document->id]], $sessionId);
        $withdrawn = $this->askQuestion('Withdrawn');
        $withdraw = self::getContainer()->get(InboxWithdrawTool::class);
        self::assertInstanceOf(InboxWithdrawTool::class, $withdraw);
        $withdraw($withdrawn['items'][0]['itemId'], 'Settled');

        self::assertSame(['Withdrawn'], array_column(($this->tool)(state: 'withdrawn')['items'], 'title'));
        self::assertSame(['Linked'], array_column(($this->tool)(state: 'open')['items'], 'title'));
        self::assertSame(['Linked'], array_column(($this->tool)(askId: $linked['askId'])['items'], 'title'));
        self::assertSame(['Linked'], array_column(($this->tool)(sessionId: $sessionId)['items'], 'title'));
        self::assertSame(['Linked'], array_column(($this->tool)(cardId: (string) $card->id)['items'], 'title'));
        self::assertSame(['Linked'], array_column(($this->tool)(documentId: (string) $document->id)['items'], 'title'));
        self::assertSame([], ($this->tool)(sessionId: (string) Uuid::v4())['items']);
    }

    public function test_paging_walks_the_list(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-list-paging'));
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->askQuestion($title);
        }

        $first = ($this->tool)(page: 1, perPage: 2);
        $second = ($this->tool)(page: 2, perPage: 2);

        self::assertSame(['Three', 'Two'], array_column($first['items'], 'title'));
        self::assertTrue($first['hasMore']);
        self::assertSame(['One'], array_column($second['items'], 'title'));
        self::assertFalse($second['hasMore']);
        self::assertSame(3, $second['total']);
    }

    public function test_an_unknown_state_names_the_ones_that_work(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-list-state'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown state "closed". Use one of: open, answered, done, declined, withdrawn, obsolete.');
        ($this->tool)(state: 'closed');
    }
}
