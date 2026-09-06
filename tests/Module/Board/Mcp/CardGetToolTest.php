<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardGetTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
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
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)('01920000-0000-7000-8000-000000000000');
    }

    public function test_a_card_reads_back_with_its_pull_request_links(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get'));
        $created = ($this->createTool)('Ship it', '## Body', 'tooling', 'low', pullRequestUrls: [
            'https://github.com/ubermuda/loupe/pull/7',
        ]);

        $card = ($this->tool)($created['cardId']);

        self::assertSame($created['cardId'], $card['cardId']);
        self::assertSame('Ship it', $card['title']);
        self::assertSame('## Body', $card['body']);
        self::assertSame('tooling', $card['type']);
        self::assertSame('low', $card['priority']);
        self::assertCount(1, $card['pullRequests']);
        self::assertSame('ubermuda/loupe', $card['pullRequests'][0]['repository']);
        self::assertSame(7, $card['pullRequests'][0]['number']);
    }

    public function test_a_card_in_another_project_is_not_reachable(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-theirs'));
        $theirs = ($this->createTool)('Not yours', 'Body', 'feature', 'high');

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
}
