<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Repository\CardLinkRepository;
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
        ($this->tool)('Ship it', 'Body', 'feature');
    }

    public function test_a_card_is_created_in_the_backlog_with_an_agent_reporter(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create'));

        $card = ($this->tool)('Ship the board', '## Why', 'feature');

        self::assertSame(1, $card['number']);
        self::assertSame('Ship the board', $card['title']);
        self::assertSame('## Why', $card['body']);
        self::assertSame('feature', $card['type']);
        self::assertArrayNotHasKey('priority', $card);
        self::assertSame('backlog', $card['status']);
        self::assertSame(CardReporter::Agent->value, $card['reporter']);
        self::assertNull($card['completedAt']);
        self::assertSame([], $card['pullRequests']);
    }

    public function test_a_caller_may_say_a_person_raised_the_card(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-human'));

        $card = ($this->tool)('Dictated', 'Body', 'idea', reporter: 'human');

        self::assertSame(CardReporter::Human->value, $card['reporter']);
    }

    /** Release 1 keeps the old parameter working, so an agent mid-upgrade is not broken. */
    public function test_the_deprecated_origin_parameter_still_sets_the_reporter(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-origin-alias'));

        $card = ($this->tool)('Old caller', 'Body', 'idea', origin: 'human');

        self::assertSame(CardReporter::Human->value, $card['reporter']);
    }

    public function test_reporter_wins_when_a_caller_sends_both_names(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-both-names'));

        $card = ($this->tool)('Both', 'Body', 'idea', reporter: 'human', origin: 'agent');

        self::assertSame(CardReporter::Human->value, $card['reporter']);
    }

    /** The widget owns `reviewer`, because it says the app could not name who raised the card. */
    public function test_a_caller_cannot_claim_the_reviewer_reporter(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-reviewer'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown reporter "reviewer". Use one of: human, agent.');
        ($this->tool)('Forged', 'Body', 'idea', reporter: 'reviewer');
    }

    public function test_pull_request_urls_are_resolved_and_an_unknown_forge_is_kept(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-links'));

        $card = ($this->tool)('Linked', 'Body', 'bug', pullRequestUrls: [
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
        ($this->tool)('   ', 'Body', 'feature');
    }

    public function test_an_unknown_status_names_the_columns_of_the_board(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-status'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown status "shipped". Use one of: backlog, next, in-progress, done.');
        ($this->tool)('Ship it', 'Body', 'feature', status: 'shipped');
    }

    public function test_related_cards_are_linked_with_their_kinds(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-links'));
        $blocker = ($this->tool)('Blocker', 'Body', 'feature');

        $card = ($this->tool)('Blocked', 'Body', 'feature', relatedCards: [['cardId' => $blocker['cardId'], 'kind' => 'blocked-by']]);

        $links = self::getContainer()->get(CardLinkRepository::class);
        self::assertInstanceOf(CardLinkRepository::class, $links);
        self::assertSame(1, $links->count([]));
        $link = $links->findOneBy([]) ?? self::fail('Expected one link.');
        self::assertSame($blocker['cardId'], (string) $link->source->id);
        self::assertSame($card['cardId'], (string) $link->target->id);
        self::assertSame(CardLinkKind::Blocks, $link->kind);
    }

    public function test_an_unknown_link_kind_lists_the_three_kinds(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-link-kind'));
        $other = ($this->tool)('Other', 'Body', 'feature');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('relatedCards[0].kind: unknown kind "sideways". Use one of: relates-to, blocks, blocked-by.');
        ($this->tool)('Linked', 'Body', 'feature', relatedCards: [['cardId' => $other['cardId'], 'kind' => 'sideways']]);
    }

    public function test_a_linked_card_of_no_card_is_reported_as_a_sentence(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-link-unknown'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('relatedCards: One of those card ids names no card of this project.');
        ($this->tool)('Linked', 'Body', 'feature', relatedCards: [['cardId' => '0199c0de-0000-7000-8000-0000000000ff']]);
    }

    public function test_a_card_is_created_under_an_epic(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-parent'));
        $epic = ($this->tool)('Epic', 'Body', 'epic');

        $card = ($this->tool)('Child', 'Body', 'feature', parentCardId: $epic['cardId']);

        self::assertSame(
            ['cardId' => $epic['cardId'], 'number' => $epic['number'], 'title' => 'Epic', 'status' => 'backlog'],
            $card['parent'],
        );
        self::assertNull($card['progress']);
        self::assertSame([], $card['children']);
    }

    public function test_the_lane_setting_is_stored_and_defaults_to_on(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-lane'));

        self::assertTrue(($this->tool)('Epic', 'Body', 'epic')['laneEnabled']);
        self::assertFalse(($this->tool)('Quiet epic', 'Body', 'epic', laneEnabled: false)['laneEnabled']);
    }

    public function test_a_parent_that_is_not_an_epic_is_reported_as_a_sentence(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-parent-not-epic'));
        $feature = ($this->tool)('Feature', 'Body', 'feature');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('parentCardId: Only a card of type epic can be a parent.');
        ($this->tool)('Child', 'Body', 'feature', parentCardId: $feature['cardId']);
    }

    public function test_an_epic_that_names_a_parent_is_reported_as_a_sentence(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-epic-parent'));
        $epic = ($this->tool)('Epic', 'Body', 'epic');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('parentCardId: An epic cannot have a parent, because epics do not nest.');
        ($this->tool)('Nested epic', 'Body', 'epic', parentCardId: $epic['cardId']);
    }

    public function test_a_parent_of_another_project_reads_as_unknown(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-parent-elsewhere'));
        $elsewhere = ($this->tool)('Epic', 'Body', 'epic');
        $this->actAsMcpTokenBoundTo($this->makeProject('card-create-parent-here'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('parentCardId: That parentCardId names no card of this project.');
        ($this->tool)('Child', 'Body', 'feature', parentCardId: $elsewhere['cardId']);
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-create-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)('Ship it', 'Body', 'feature');
    }
}
