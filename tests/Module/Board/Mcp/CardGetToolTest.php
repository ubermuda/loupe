<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardGetTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardGetToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardGetTool $tool;
    private CardCreateTool $createTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardGetTool::class);
        self::assertInstanceOf(CardGetTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->disableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)('01920000-0000-7000-8000-000000000000');
    }

    public function test_a_card_reads_back_with_its_pull_request_links(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get'));
        $created = ($this->createTool)('Ship it', '## Body', 'tooling', pullRequestUrls: [
            'https://github.com/ubermuda/loupe/pull/7',
        ]);

        $card = ($this->tool)($created['cardId']);

        self::assertSame($created['cardId'], $card['cardId']);
        self::assertSame($created['number'], $card['number']);
        self::assertSame(1, $card['number']);
        self::assertSame('Ship it', $card['title']);
        self::assertSame('## Body', $card['body']);
        self::assertSame('tooling', $card['type']);
        self::assertCount(1, $card['pullRequests']);
        self::assertSame('ubermuda/loupe', $card['pullRequests'][0]['repository']);
        self::assertSame(7, $card['pullRequests'][0]['number']);
    }

    public function test_each_card_reads_its_links_from_its_own_side(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-links'));
        $blocker = ($this->createTool)('Blocker', 'Body', 'feature');
        $blocked = ($this->createTool)('Blocked', 'Body', 'feature', relatedCards: [['cardId' => $blocker['cardId'], 'kind' => 'blocked-by']]);

        self::assertSame(
            [['cardId' => $blocker['cardId'], 'number' => 1, 'title' => 'Blocker', 'status' => 'backlog', 'kind' => 'blocked-by']],
            ($this->tool)($blocked['cardId'])['relatedCards'],
        );
        self::assertSame(
            [['cardId' => $blocked['cardId'], 'number' => 2, 'title' => 'Blocked', 'status' => 'backlog', 'kind' => 'blocks']],
            ($this->tool)($blocker['cardId'])['relatedCards'],
        );
    }

    public function test_a_card_in_another_project_is_not_reachable(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-theirs'));
        $theirs = ($this->createTool)('Not yours', 'Body', 'feature');

        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)($theirs['cardId']);
    }

    public function test_a_malformed_id_is_reported_rather_than_fatal(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-malformed'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"not-a-uuid" is not a valid card ID.');
        ($this->tool)('not-a-uuid');
    }

    public function test_a_card_reads_back_by_its_number(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-number'));
        ($this->createTool)('First', 'Body', 'feature');
        $second = ($this->createTool)('Second', 'Body', 'feature');

        $card = ($this->tool)(number: 2);

        self::assertSame($second['cardId'], $card['cardId']);
        self::assertSame(2, $card['number']);
    }

    public function test_a_number_reads_the_card_of_the_bound_project(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-number-theirs'));
        $theirs = ($this->createTool)('Theirs', 'Body', 'feature');
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-number-mine'));
        $mine = ($this->createTool)('Mine', 'Body', 'feature');
        self::assertSame($theirs['number'], $mine['number']);

        $card = ($this->tool)(number: 1);

        self::assertSame($mine['cardId'], $card['cardId']);
    }

    public function test_an_unknown_number_is_refused_with_the_number(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-number-unknown'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This project has no card 7.');
        ($this->tool)(number: 7);
    }

    /** @return iterable<string, array{?string, ?int, string}> */
    public static function refusedHandles(): iterable
    {
        yield 'zero' => [null, 0, 'Card numbers count from 1, so 0 is not a card number.'];
        yield 'negative' => [null, -3, 'Card numbers count from 1, so -3 is not a card number.'];
        yield 'both' => ['01920000-0000-7000-8000-000000000000', 1, 'Pass cardId or number, not both.'];
        yield 'neither' => [null, null, 'Pass cardId or number.'];
    }

    #[DataProvider('refusedHandles')]
    public function test_a_bad_handle_is_refused(?string $cardId, ?int $number, string $message): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-refused'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        ($this->tool)($cardId, $number);
    }
}
