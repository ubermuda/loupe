<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Tests\Module\Board\CardMovedOutbox;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
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
