<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Mcp\InboxWithdrawTool;
use App\Security\McpBoundProjectVoter;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
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
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        // Installed before the tool is built, so the voter it reaches records here.
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

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

    public function test_a_reason_over_the_limit_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-long'));
        $asked = $this->askQuestion('Which column?');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('reason: A withdraw reason must be at most 2000 characters.');
        ($this->tool)($asked['items'][0]['itemId'], str_repeat('a', 2001));
    }

    /** The schema measures the reason as sent, so the handler does too, spaces included. */
    public function test_a_reason_over_the_limit_only_with_its_spaces_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-spaces'));
        $asked = $this->askQuestion('Which column?');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('reason: A withdraw reason must be at most 2000 characters.');
        ($this->tool)($asked['items'][0]['itemId'], str_repeat('a', 2000).' ');
    }

    public function test_an_item_in_another_project_is_not_reachable_and_the_refusal_is_audited(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-theirs'));
        $theirs = $this->askQuestion('Not yours');

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-withdraw-mine'));

        try {
            ($this->tool)($theirs['items'][0]['itemId'], 'Mine now');
            self::fail('Expected a refusal for an item of another project.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        $record = $this->audit->record('inbox.mcp_access_denied');
        self::assertSame(McpBoundProjectVoter::INBOX_ITEM_WRITE, $record->context['attribute']);
        self::assertSame($theirs['items'][0]['itemId'], $record->context['subjectId']);
    }
}
