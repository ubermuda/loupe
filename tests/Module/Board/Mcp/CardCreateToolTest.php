<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Mcp\CardCreateTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardCreateToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardCreateTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)('Ship it', 'Body', 'feature', 'high');
    }

    public function test_a_card_is_created_in_the_backlog_with_an_agent_origin(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create'));

        $card = ($this->tool)('Ship the board', '## Why', 'feature', 'high');

        self::assertSame(1, $card['number']);
        self::assertSame('Ship the board', $card['title']);
        self::assertSame('## Why', $card['body']);
        self::assertSame('feature', $card['type']);
        self::assertSame('high', $card['priority']);
        self::assertSame('backlog', $card['status']);
        self::assertSame(CardOrigin::Agent->value, $card['origin']);
        self::assertNull($card['completedAt']);
        self::assertSame([], $card['pullRequests']);
    }

    public function test_a_caller_may_say_a_person_raised_the_card(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-human'));

        $card = ($this->tool)('Dictated', 'Body', 'idea', 'low', origin: 'human');

        self::assertSame(CardOrigin::Human->value, $card['origin']);
    }

    public function test_pull_request_urls_are_resolved_and_an_unknown_forge_is_kept(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-links'));

        $card = ($this->tool)('Linked', 'Body', 'bug', 'medium', pullRequestUrls: [
            'https://github.com/ubermuda/loupe/pull/362',
            'https://code.example.org/team/app/pulls/12',
        ]);

        self::assertCount(2, $card['pullRequests']);
        self::assertSame(Forge::GitHub->value, $card['pullRequests'][0]['forge']);
        self::assertSame('ubermuda/loupe', $card['pullRequests'][0]['repository']);
        self::assertSame(362, $card['pullRequests'][0]['number']);
        self::assertSame(Forge::Other->value, $card['pullRequests'][1]['forge']);
        self::assertNull($card['pullRequests'][1]['repository']);
        self::assertNull($card['pullRequests'][1]['number']);
    }

    public function test_a_blank_title_is_reported_as_a_sentence(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-blank'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('title: A card title must not be blank.');
        ($this->tool)('   ', 'Body', 'feature', 'high');
    }

    public function test_an_unknown_priority_names_the_ones_that_work(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-priority'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown priority "urgent". Use one of: high, medium, low.');
        ($this->tool)('Ship it', 'Body', 'feature', 'urgent');
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-create-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)('Ship it', 'Body', 'feature', 'high');
    }
}
