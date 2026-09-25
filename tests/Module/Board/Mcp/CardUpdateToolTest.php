<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Board\Repository\CardLinkRepository;
use App\Tests\Module\Board\CardMovedOutbox;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-import-type CardSummary from CardPayload
 */
final class CardUpdateToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private const string PULL_REQUEST = 'https://github.com/ubermuda/loupe/pull/362';

    private EntityManagerInterface $em;
    private CardUpdateTool $tool;
    private CardCreateTool $createTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardUpdateTool::class);
        self::assertInstanceOf(CardUpdateTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->disableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-update-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)('01920000-0000-7000-8000-000000000000', title: 'New');
    }

    public function test_the_named_fields_change_and_the_rest_stay(): void
    {
        $created = $this->card('card-update');

        $card = ($this->tool)($created['cardId'], title: 'Renamed');

        self::assertSame('Renamed', $card['title']);
        self::assertSame($created['body'], $card['body']);
        self::assertSame($created['type'], $card['type']);
        self::assertSame($created['status'], $card['status']);
        self::assertSame($created['number'], $card['number']);
        self::assertSame(1, $card['number']);
    }

    public function test_an_omitted_pull_request_list_leaves_the_links_alone(): void
    {
        $created = $this->card('card-update-omitted');
        self::assertCount(1, $created['pullRequests']);

        $card = ($this->tool)($created['cardId'], title: 'Renamed');

        self::assertCount(1, $card['pullRequests']);
        self::assertSame(self::PULL_REQUEST, $card['pullRequests'][0]['url']);
    }

    public function test_an_empty_pull_request_list_clears_the_links(): void
    {
        $created = $this->card('card-update-empty');
        self::assertCount(1, $created['pullRequests']);

        $card = ($this->tool)($created['cardId'], pullRequestUrls: []);

        self::assertSame([], $card['pullRequests']);
    }

    public function test_a_new_pull_request_list_replaces_the_old_one(): void
    {
        $created = $this->card('card-update-replace');

        $card = ($this->tool)($created['cardId'], pullRequestUrls: ['https://github.com/ubermuda/loupe/pull/9']);

        self::assertCount(1, $card['pullRequests']);
        self::assertSame(9, $card['pullRequests'][0]['number']);
    }

    public function test_reporter_cannot_be_changed(): void
    {
        $created = $this->card('card-update-reporter');
        self::assertSame(CardReporter::Agent->value, $created['reporter']);

        $card = ($this->tool)($created['cardId'], title: 'Renamed');

        self::assertSame(CardReporter::Agent->value, $card['reporter']);
        self::assertArrayNotHasKey('reporter', $this->publishedParameters());
    }

    public function test_moving_to_done_stamps_the_completion_and_moving_out_clears_it(): void
    {
        $created = $this->card('card-update-done');
        self::assertNull($created['completedAt']);

        $done = ($this->tool)($created['cardId'], status: 'done');
        self::assertSame('done', $done['status']);
        self::assertNotNull($done['completedAt']);

        $reopened = ($this->tool)($created['cardId'], status: 'in-progress');
        self::assertSame('in-progress', $reopened['status']);
        self::assertNull($reopened['completedAt']);
    }

    public function test_a_move_through_the_tool_is_published_as_an_agent_action(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-update-actor');
        $this->actAsMcpTokenBoundTo($project);
        $created = ($this->createTool)('Ship it', 'Body', 'feature');

        ($this->tool)($created['cardId'], status: 'next');

        $payload = CardMovedOutbox::onlyPayload(self::getContainer(), $project);
        self::assertSame($created['cardId'], $payload['subject']['id'] ?? null);
        self::assertSame(CardReporter::Agent->value, $payload['actor'] ?? null);
    }

    public function test_an_unknown_status_names_the_ones_that_work(): void
    {
        $created = $this->card('card-update-status');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown status "shipped". Use one of: backlog, next, in-progress, done.');
        ($this->tool)($created['cardId'], status: 'shipped');
    }

    public function test_status_takes_the_slugs_of_this_board_and_no_other(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-update-own-columns');
        $this->em->persist(new BoardColumn(project: $project, label: 'Won’t do', slug: 'wont-do', position: 4, terminal: true));
        $elsewhere = $this->makeProject('card-update-other-columns');
        $this->em->persist(new BoardColumn(project: $elsewhere, label: 'Parked', slug: 'parked', position: 4));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        $created = ($this->createTool)('Ship it', 'Body', 'feature');

        $dropped = ($this->tool)($created['cardId'], status: 'wont-do');
        self::assertSame('wont-do', $dropped['status']);
        self::assertNotNull($dropped['completedAt']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown status "parked". Use one of: backlog, next, in-progress, done, wont-do.');
        ($this->tool)($created['cardId'], status: 'parked');
    }

    public function test_a_change_by_number_applies_to_that_card(): void
    {
        $first = $this->card('card-update-number');
        $second = ($this->createTool)('Second', 'Body', 'feature');

        $card = ($this->tool)(number: 2, title: 'Renamed');

        self::assertSame($second['cardId'], $card['cardId']);
        self::assertSame('Renamed', $card['title']);
        self::assertSame('Ship it', ($this->tool)($first['cardId'])['title']);
    }

    public function test_an_omitted_related_card_list_keeps_the_links_and_an_empty_one_clears_them(): void
    {
        $created = $this->card('card-update-links');
        $other = ($this->createTool)('Other', 'Body', 'feature');
        ($this->tool)($created['cardId'], relatedCards: [['cardId' => $other['cardId'], 'kind' => 'blocks']]);
        self::assertSame(1, $this->links()->count([]));

        ($this->tool)($created['cardId'], title: 'Renamed');
        self::assertSame(1, $this->links()->count([]));

        ($this->tool)($other['cardId'], relatedCards: []);
        self::assertSame(0, $this->links()->count([]));
    }

    public function test_an_unknown_link_kind_lists_the_three_kinds(): void
    {
        $created = $this->card('card-update-link-kind');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('relatedCards[0].kind: unknown kind "sideways". Use one of: relates-to, blocks, blocked-by.');
        ($this->tool)($created['cardId'], relatedCards: [['cardId' => $created['cardId'], 'kind' => 'sideways']]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedLinkItems(): iterable
    {
        yield 'not an object' => ['0199c0de-0000-7000-8000-0000000000ff', 'relatedCards[0] must be an object with a cardId.'];
        yield 'no card id' => [['kind' => 'blocks'], 'relatedCards[0].cardId must be a string.'];
        yield 'a card id that is not a string' => [['cardId' => 42], 'relatedCards[0].cardId must be a string.'];
        yield 'a kind that is not a string' => [['cardId' => 'x', 'kind' => 1], 'relatedCards[0].kind: unknown kind "int". Use one of: relates-to, blocks, blocked-by.'];
    }

    #[DataProvider('malformedLinkItems')]
    public function test_a_malformed_link_item_is_refused(mixed $item, string $message): void
    {
        $created = $this->card('card-update-link-item');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        ($this->tool)($created['cardId'], relatedCards: [$item]);
    }

    public function test_the_card_itself_is_reported_as_a_sentence(): void
    {
        $created = $this->card('card-update-link-self');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('relatedCards: A card cannot link to itself.');
        ($this->tool)($created['cardId'], relatedCards: [['cardId' => $created['cardId']]]);
    }

    public function test_a_parent_is_set_kept_when_omitted_and_cleared_by_an_empty_string(): void
    {
        $created = $this->card('card-update-parent');
        $epic = ($this->createTool)('Epic', 'Body', 'epic');

        $set = ($this->tool)($created['cardId'], parentCardId: $epic['cardId']);
        self::assertSame($epic['cardId'], $set['parent']['cardId'] ?? null);

        $kept = ($this->tool)($created['cardId'], title: 'Renamed');
        self::assertSame($epic['cardId'], $kept['parent']['cardId'] ?? null);

        $cleared = ($this->tool)($created['cardId'], parentCardId: '');
        self::assertNull($cleared['parent']);
    }

    public function test_an_epic_reads_its_children_and_its_progress(): void
    {
        $this->card('card-update-epic-payload');
        $epic = ($this->createTool)('Epic', 'Body', 'epic');
        $open = ($this->createTool)('Open child', 'Body', 'feature', parentCardId: $epic['cardId']);
        $done = ($this->createTool)('Done child', 'Body', 'feature', status: 'done', parentCardId: $epic['cardId']);

        $card = ($this->tool)($epic['cardId'], title: 'Epic renamed');

        self::assertSame(['done' => 1, 'total' => 2], $card['progress']);
        self::assertSame(
            [
                ['cardId' => $open['cardId'], 'number' => $open['number'], 'title' => 'Open child', 'status' => 'backlog'],
                ['cardId' => $done['cardId'], 'number' => $done['number'], 'title' => 'Done child', 'status' => 'done'],
            ],
            $card['children'],
        );
        self::assertNull($card['parent']);
    }

    public function test_an_epic_with_no_children_reads_zero_of_zero(): void
    {
        $this->card('card-update-empty-epic');
        $epic = ($this->createTool)('Epic', 'Body', 'epic');

        $card = ($this->tool)($epic['cardId'], title: 'Still empty');

        self::assertSame(['done' => 0, 'total' => 0], $card['progress']);
        self::assertSame([], $card['children']);
    }

    public function test_the_lane_setting_changes_and_an_omitted_one_stays(): void
    {
        $this->card('card-update-lane');
        $epic = ($this->createTool)('Epic', 'Body', 'epic');

        self::assertFalse(($this->tool)($epic['cardId'], laneEnabled: false)['laneEnabled']);
        self::assertFalse(($this->tool)($epic['cardId'], title: 'Renamed')['laneEnabled']);
        self::assertTrue(($this->tool)($epic['cardId'], laneEnabled: true)['laneEnabled']);
    }

    /** @return iterable<string, array{string}> */
    public static function parentRefusals(): iterable
    {
        yield 'a parent that is not an epic' => ['not-epic'];
        yield 'an epic that takes a parent' => ['epic-with-parent'];
        yield 'a child that becomes an epic' => ['child-to-epic'];
        yield 'an epic with children that changes its type' => ['type-locked'];
        yield 'a parent of no card' => ['unknown'];
    }

    #[DataProvider('parentRefusals')]
    public function test_a_parent_refusal_is_reported_as_a_sentence(string $case): void
    {
        $created = $this->card('card-update-parent-refusal-'.$case);
        $epic = ($this->createTool)('Epic', 'Body', 'epic');
        $child = ($this->createTool)('Child', 'Body', 'feature', parentCardId: $epic['cardId']);
        $otherEpic = ($this->createTool)('Other epic', 'Body', 'epic');

        [$message, $call] = match ($case) {
            'not-epic' => ['parentCardId: Only a card of type epic can be a parent.', fn () => ($this->tool)($child['cardId'], parentCardId: $created['cardId'])],
            'epic-with-parent' => ['parentCardId: An epic cannot have a parent, because epics do not nest.', fn () => ($this->tool)($otherEpic['cardId'], parentCardId: $epic['cardId'])],
            'child-to-epic' => ['type: A card with a parent cannot become an epic.', fn () => ($this->tool)($child['cardId'], type: 'epic')],
            'type-locked' => ['type: This epic has child cards, so its type stays epic.', fn () => ($this->tool)($epic['cardId'], type: 'feature')],
            default => ['parentCardId: That parentCardId names no card of this project.', fn () => ($this->tool)($created['cardId'], parentCardId: '0199c0de-0000-7000-8000-0000000000ff')],
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        $call();
    }

    public function test_an_epic_with_open_children_is_not_moved_to_done(): void
    {
        $this->card('card-update-epic-open');
        $epic = ($this->createTool)('Epic', 'Body', 'epic');
        $first = ($this->createTool)('First', 'Body', 'feature', parentCardId: $epic['cardId']);
        $second = ($this->createTool)('Second', 'Body', 'feature', parentCardId: $epic['cardId']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(\sprintf('status: This epic has open child cards #%d, #%d. Move each of them to a terminal column first.', $first['number'], $second['number']));
        ($this->tool)($epic['cardId'], status: 'done');
    }

    public function test_both_handles_are_refused(): void
    {
        $created = $this->card('card-update-both');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass cardId or number, not both.');
        ($this->tool)($created['cardId'], 1, title: 'Renamed');
    }

    public function test_neither_handle_is_refused(): void
    {
        $this->card('card-update-neither');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass cardId or number.');
        ($this->tool)(title: 'Renamed');
    }

    private function links(): CardLinkRepository
    {
        $links = self::getContainer()->get(CardLinkRepository::class);
        self::assertInstanceOf(CardLinkRepository::class, $links);

        return $links;
    }

    /** @return array<string, true> */
    private function publishedParameters(): array
    {
        $reflection = new \ReflectionMethod(CardUpdateTool::class, '__invoke');

        $names = [];
        foreach ($reflection->getParameters() as $parameter) {
            $names[$parameter->getName()] = true;
        }

        return $names;
    }

    /** @return CardSummary */
    private function card(string $label): array
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject($label));

        return ($this->createTool)('Ship it', 'Body', 'feature', pullRequestUrls: [self::PULL_REQUEST]);
    }
}
