<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Mcp\CardUpdateTool;
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

        $card = ($this->tool)($created['cardId'], title: 'Renamed', priority: 'low');

        self::assertSame('Renamed', $card['title']);
        self::assertSame('low', $card['priority']);
        self::assertSame($created['body'], $card['body']);
        self::assertSame($created['type'], $card['type']);
        self::assertSame($created['status'], $card['status']);
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

    public function test_origin_cannot_be_changed(): void
    {
        $created = $this->card('card-update-origin');
        self::assertSame(CardOrigin::Agent->value, $created['origin']);

        $card = ($this->tool)($created['cardId'], title: 'Renamed');

        self::assertSame(CardOrigin::Agent->value, $card['origin']);
        self::assertArrayNotHasKey('origin', $this->publishedParameters());
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

    public function test_an_unknown_status_names_the_ones_that_work(): void
    {
        $created = $this->card('card-update-status');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown status "shipped". Use one of: backlog, next, in-progress, done.');
        ($this->tool)($created['cardId'], status: 'shipped');
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

        return ($this->createTool)('Ship it', 'Body', 'feature', 'high', pullRequestUrls: [self::PULL_REQUEST]);
    }
}
