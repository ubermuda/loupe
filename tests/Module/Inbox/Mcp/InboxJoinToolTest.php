<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Mcp\InboxJoinTool;
use App\Module\Inbox\Mcp\InboxWithdrawTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxJoinToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxJoinTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(InboxJoinTool::class);
        self::assertInstanceOf(InboxJoinTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4(), (string) Uuid::v4());
    }

    public function test_a_session_with_no_ask_opens_one_that_holds_the_item(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-open'));
        $asked = $this->askQuestion('Which column?');
        $sessionId = (string) Uuid::v4();
        $bridgeId = (string) Uuid::v4();

        $result = ($this->tool)($asked['items'][0]['itemId'], $sessionId, $bridgeId);

        self::assertFalse($result['extended']);
        self::assertFalse($result['closed']);
        self::assertNotSame($asked['askId'], $result['askId']);
        self::assertSame($asked['items'][0]['itemId'], $result['items'][0]['itemId']);

        $this->em->clear();
        $ask = $this->em->find(InboxAsk::class, Uuid::fromString($result['askId']));
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertSame($sessionId, (string) $ask->sessionId);
        self::assertSame($bridgeId, (string) $ask->bridgeId);
        self::assertCount(1, $ask->items);
    }

    public function test_the_item_joins_the_session_s_open_ask_once(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-extend'));
        $sessionId = (string) Uuid::v4();
        $mine = $this->askQuestion('Mine', sessionId: $sessionId);
        $theirs = $this->askQuestion('Theirs');

        $result = ($this->tool)($theirs['items'][0]['itemId'], $sessionId);
        $again = ($this->tool)($theirs['items'][0]['itemId'], $sessionId);

        self::assertTrue($result['extended']);
        self::assertSame($mine['askId'], $result['askId']);
        self::assertSame($mine['askId'], $again['askId']);

        $this->em->clear();
        $ask = $this->em->find(InboxAsk::class, Uuid::fromString($mine['askId']));
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertCount(2, $ask->items);
    }

    public function test_a_new_ask_whose_item_does_not_block_closes_at_once(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-no-blocking'));
        $todo = ($this->askTool())(sessionId: (string) Uuid::v4(), items: [['kind' => 'todo', 'title' => 'Review pull request 482']]);

        $result = ($this->tool)($todo['items'][0]['itemId'], (string) Uuid::v4());

        self::assertTrue($result['closed']);
        self::assertSame('open', $result['items'][0]['state']);
    }

    public function test_a_closed_item_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-closed'));
        $asked = $this->askQuestion('Which column?');
        $withdraw = self::getContainer()->get(InboxWithdrawTool::class);
        self::assertInstanceOf(InboxWithdrawTool::class, $withdraw);
        $withdraw($asked['items'][0]['itemId'], 'Settled');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('itemId: The item is already closed.');
        ($this->tool)($asked['items'][0]['itemId'], (string) Uuid::v4());
    }

    public function test_an_item_in_another_project_is_not_reachable(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-theirs'));
        $theirs = $this->askQuestion('Not yours');

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-join-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)($theirs['items'][0]['itemId'], (string) Uuid::v4());
    }
}
