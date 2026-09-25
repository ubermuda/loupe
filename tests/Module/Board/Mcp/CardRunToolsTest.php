<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Mcp\CardRunCloseTool;
use App\Module\Board\Mcp\CardRunOpenTool;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\CardMovedOutbox;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-import-type CardSummary from CardPayload
 */
final class CardRunToolsTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private const string SKILL = 'loupe:product-design';

    private EntityManagerInterface $em;
    private CardRunOpenTool $open;
    private CardRunCloseTool $close;
    private CardCreateTool $createTool;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $open = self::getContainer()->get(CardRunOpenTool::class);
        self::assertInstanceOf(CardRunOpenTool::class, $open);
        $this->open = $open;

        $close = self::getContainer()->get(CardRunCloseTool::class);
        self::assertInstanceOf(CardRunCloseTool::class, $close);
        $this->close = $close;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_both_tools_refuse_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-run-flag-off'));
        $sessionId = (string) Uuid::v4();

        foreach ([
            fn (): array => ($this->open)($sessionId, self::SKILL, number: 1),
            fn (): array => ($this->close)($sessionId, number: 1),
        ] as $call) {
            try {
                $call();
                self::fail('the tool must refuse');
            } catch (ToolCallException $e) {
                self::assertSame('The board is switched off on this instance.', $e->getMessage());
            }
        }
    }

    public function test_open_records_a_running_run_and_returns_the_card(): void
    {
        $created = $this->card('card-run-open');
        $sessionId = (string) Uuid::v4();

        $result = ($this->open)($sessionId, '  '.self::SKILL.'  ', $created['cardId']);

        self::assertSame($created['cardId'], $result['cardId']);
        self::assertSame($created['title'], $result['title']);
        self::assertSame('running', $result['run']['state']);
        self::assertSame(self::SKILL, $result['run']['name']);
        self::assertTrue($this->runs()->hasOpenRun($this->project, Uuid::fromString($created['cardId'])));
        self::assertSame($result['run']['runId'], ($this->open)($sessionId, self::SKILL, $created['cardId'])['run']['runId'], 'a second open returns the same run');
    }

    public function test_open_with_a_status_moves_the_card_and_keeps_the_run_open(): void
    {
        $created = $this->card('card-run-move');

        $result = ($this->open)((string) Uuid::v4(), self::SKILL, number: $created['number'], status: 'in-progress');

        self::assertSame('in-progress', $result['status']);
        self::assertSame('running', $result['run']['state']);
        $payload = CardMovedOutbox::onlyPayload(self::getContainer(), $this->project);
        self::assertSame('agent', $payload['actor'] ?? null);
        self::assertSame(['interactiveRun' => true], $payload['card'] ?? null);
    }

    public function test_close_closes_the_run_and_a_second_close_changes_nothing(): void
    {
        $created = $this->card('card-run-close');
        $sessionId = (string) Uuid::v4();
        $runId = ($this->open)($sessionId, self::SKILL, $created['cardId'])['run']['runId'];

        $first = ($this->close)($sessionId, $created['cardId']);
        $second = ($this->close)($sessionId, number: $created['number']);

        $expected = ['cardId' => $created['cardId'], 'number' => $created['number'], 'run' => ['runId' => $runId, 'state' => 'closed']];
        self::assertSame($expected, $first);
        self::assertSame($expected, $second);
        self::assertFalse($this->runs()->hasOpenRun($this->project, Uuid::fromString($created['cardId'])));
    }

    public function test_close_without_a_run_returns_no_run(): void
    {
        $created = $this->card('card-run-none');

        $result = ($this->close)((string) Uuid::v4(), $created['cardId']);

        self::assertSame(['cardId' => $created['cardId'], 'number' => $created['number'], 'run' => null], $result);
    }

    public function test_a_session_id_that_is_not_a_uuid_is_refused(): void
    {
        $created = $this->card('card-run-session');

        foreach ([
            fn (): array => ($this->open)('not-a-session', self::SKILL, $created['cardId']),
            fn (): array => ($this->close)('not-a-session', $created['cardId']),
        ] as $call) {
            try {
                $call();
                self::fail('the tool must refuse');
            } catch (ToolCallException $e) {
                self::assertSame('"not-a-session" is not a valid sessionId. Pass the value of $CLAUDE_CODE_SESSION_ID.', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function badNames(): iterable
    {
        yield 'blank' => ['   ', 'name: Pass the name of the skill that runs the session, such as loupe:product-design.'];
        yield 'too long' => [str_repeat('a', WorkerRun::MAX_RULE_NAME_LENGTH + 1), \sprintf('name: A run name must be at most %d characters.', WorkerRun::MAX_RULE_NAME_LENGTH)];
    }

    #[DataProvider('badNames')]
    public function test_a_bad_name_is_refused_and_nothing_changes(string $name, string $message): void
    {
        $created = $this->card('card-run-name');

        try {
            ($this->open)((string) Uuid::v4(), $name, $created['cardId'], status: 'done');
            self::fail('the tool must refuse');
        } catch (ToolCallException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertFalse($this->runs()->hasOpenRun($this->project, Uuid::fromString($created['cardId'])));
        self::assertSame('backlog', $this->em->getConnection()->fetchOne('SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = :id', ['id' => $created['cardId']]));
    }

    public function test_both_handles_are_refused(): void
    {
        $created = $this->card('card-run-both');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass cardId or number, not both.');
        ($this->open)((string) Uuid::v4(), self::SKILL, $created['cardId'], 1);
    }

    private function runs(): InteractiveRuns
    {
        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);

        return $runs;
    }

    /** @return CardSummary */
    private function card(string $label): array
    {
        $this->enableBoard();
        $this->project = $this->makeProject($label);
        $this->actAsMcpTokenBoundTo($this->project);

        return ($this->createTool)('Design it', 'Body', 'feature', status: 'backlog');
    }
}
