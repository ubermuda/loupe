<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Mcp\InboxWithdrawTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxWithdrawToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxWithdrawTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(InboxWithdrawTool::class);
        self::assertInstanceOf(InboxWithdrawTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4(), 'Settled');
    }

    public function test_an_open_item_closes_as_withdrawn_with_the_reason(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw'));
        $asked = $this->askQuestion('Which column?');

        $result = ($this->tool)($asked['items'][0]['itemId'], '  The pull request merged  ');

        self::assertSame('withdrawn', $result['state']);
        self::assertNotNull($result['closedAt']);

        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($asked['items'][0]['itemId']));
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(InboxItemState::Withdrawn, $item->state);
        self::assertSame('The pull request merged', $item->closeNote);
        self::assertNotNull($item->closedAt);
    }

    public function test_a_closed_item_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-twice'));
        $asked = $this->askQuestion('Which column?');
        ($this->tool)($asked['items'][0]['itemId'], 'First');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('itemId: The item is already closed.');
        ($this->tool)($asked['items'][0]['itemId'], 'Second');
    }

    public function test_a_blank_reason_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-blank'));
        $asked = $this->askQuestion('Which column?');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('reason: Pass the reason you no longer need the item.');
        ($this->tool)($asked['items'][0]['itemId'], '  ');
    }

    public function test_an_item_in_another_project_is_not_reachable(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-theirs'));
        $theirs = $this->askQuestion('Not yours');

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)($theirs['items'][0]['itemId'], 'Mine now');
    }
}
