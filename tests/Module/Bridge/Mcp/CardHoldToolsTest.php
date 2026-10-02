<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Mcp\CardHoldTool;
use App\Module\Bridge\Mcp\CardReleaseTool;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardHoldToolsTest extends KernelTestCase
{
    use BridgeScenario;
    use McpTokenScenario;

    private Project $project;
    private Project $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'mcp-hold-'.uniqid().'@example.com');
        $this->project = $this->project($em, $owner, 'Bound');
        $this->other = $this->project($em, $owner, 'Other');
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_a_hold_pauses_the_card_for_the_token_user_and_names_the_agent(): void
    {
        $cardId = $this->card($this->project, 1);

        $result = $this->hold()((string) $cardId);

        self::assertSame(['cardId' => (string) $cardId, 'outcome' => 'held'], $result);
        $hold = $this->holds()->findOneOfCard($this->project, $cardId);
        self::assertNotNull($hold);
        self::assertSame((string) $this->project->owner->id, (string) $hold->heldBy?->id);
        self::assertSame([[
            'type' => 'board.card_held',
            'subject' => ['type' => 'card', 'id' => (string) $cardId],
            'projectId' => (string) $this->project->id,
            'actor' => 'agent',
        ]], $this->outboxPayloads('board.card_held'));
    }

    public function test_a_hold_by_number_pauses_the_card_with_that_number(): void
    {
        $this->card($this->project, 1);
        $cardId = $this->card($this->project, 2);

        $result = $this->hold()(number: 2);

        self::assertSame(['cardId' => (string) $cardId, 'outcome' => 'held'], $result);
        self::assertTrue($this->cardHolds()->isHeld($this->project, $cardId));
    }

    public function test_a_hold_of_a_paused_card_is_refused(): void
    {
        $cardId = $this->card($this->project, 1);
        $this->cardHolds()->hold($this->project, $cardId, null);

        $result = $this->hold()((string) $cardId);

        self::assertSame([
            'cardId' => (string) $cardId,
            'outcome' => 'refused',
            'code' => 'already-paused',
            'message' => 'Agents are already paused on this card.',
        ], $result);
        self::assertSame([], $this->outboxPayloads('board.card_held'));
    }

    public function test_a_hold_of_an_unknown_card_is_not_found(): void
    {
        $unknown = (string) Uuid::v7();

        self::assertSame([
            'cardId' => $unknown,
            'outcome' => 'refused',
            'code' => 'not-found',
            'message' => \sprintf('Card "%s" not found or not accessible.', $unknown),
        ], $this->hold()($unknown));
        self::assertSame([
            'cardId' => null,
            'outcome' => 'refused',
            'code' => 'not-found',
            'message' => 'This project has no card 9.',
        ], $this->hold()(number: 9));
        self::assertSame([], $this->outboxPayloads('board.card_held'));
    }

    public function test_a_hold_of_a_card_of_another_project_is_not_found(): void
    {
        $foreign = $this->card($this->other, 1);

        self::assertSame('not-found', $this->hold()((string) $foreign)['code'] ?? null);
        self::assertFalse($this->cardHolds()->isHeld($this->other, $foreign));
        self::assertFalse($this->cardHolds()->isHeld($this->project, $foreign));
    }

    public function test_a_release_ends_the_pause_and_names_the_agent(): void
    {
        $cardId = $this->card($this->project, 1);
        $this->cardHolds()->hold($this->project, $cardId, null);

        $result = $this->release()(number: 1);

        self::assertSame(['cardId' => (string) $cardId, 'outcome' => 'released'], $result);
        self::assertFalse($this->cardHolds()->isHeld($this->project, $cardId));
        self::assertSame(['agent'], array_column($this->outboxPayloads('board.card_released'), 'actor'));
    }

    public function test_a_release_of_a_card_that_is_not_paused_is_refused(): void
    {
        $cardId = $this->card($this->project, 1);

        self::assertSame([
            'cardId' => (string) $cardId,
            'outcome' => 'refused',
            'code' => 'not-paused',
            'message' => 'Agents are not paused on this card.',
        ], $this->release()((string) $cardId));
    }

    public function test_a_release_of_a_card_of_another_project_is_not_found(): void
    {
        $foreign = $this->card($this->other, 1);
        $this->cardHolds()->hold($this->other, $foreign, null);

        self::assertSame('not-found', $this->release()((string) $foreign)['code'] ?? null);
        self::assertTrue($this->cardHolds()->isHeld($this->other, $foreign));
    }

    public function test_a_release_of_an_unknown_card_is_not_found(): void
    {
        self::assertSame('not-found', $this->release()(number: 4)['code'] ?? null);
    }

    public function test_both_arguments_or_neither_are_refused(): void
    {
        $cardId = (string) $this->card($this->project, 1);

        foreach ([[$cardId, 1, 'Pass cardId or number, not both.'], [null, null, 'Pass cardId or number.']] as [$id, $number, $message]) {
            foreach ([$this->hold(...), $this->release(...)] as $tool) {
                try {
                    $tool()($id, $number);
                    self::fail('Expected a refusal.');
                } catch (ToolCallException $e) {
                    self::assertSame($message, $e->getMessage());
                }
            }
        }
        self::assertSame([], $this->outboxPayloads('board.card_held'));
    }

    public function test_a_malformed_card_id_is_refused(): void
    {
        try {
            $this->hold()('card-7');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('"card-7" is not a valid card ID.', $e->getMessage());
        }

        try {
            $this->release()('7');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('"7" is not a valid card ID. To read a card by its number, pass number instead.', $e->getMessage());
        }
    }

    public function test_a_number_below_one_is_refused(): void
    {
        $this->expectExceptionObject(new ToolCallException('Card numbers count from 1, so 0 is not a card number.'));

        $this->hold()(number: 0);
    }

    public function test_a_hold_with_an_unbound_token_is_refused(): void
    {
        $cardId = $this->card($this->project, 1);
        $this->actAsUnboundMcpToken($this->project->owner);

        try {
            $this->hold()((string) $cardId);
            self::fail('Expected a refusal.');
        } catch (ToolCallException) {
        }

        self::assertFalse($this->cardHolds()->isHeld($this->project, $cardId));
    }

    private function card(Project $project, int $number): Uuid
    {
        $column = new BoardColumn($project, 'Implementation', 'implementation-'.$number, $number);
        $card = new Card($project, $column, 'Card '.$number, '', $number);
        $this->em()->persist($column);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card->id ?? throw new \LogicException('A flushed card has an id.');
    }

    private function hold(): CardHoldTool
    {
        $tool = self::getContainer()->get(CardHoldTool::class);
        self::assertInstanceOf(CardHoldTool::class, $tool);

        return $tool;
    }

    private function release(): CardReleaseTool
    {
        $tool = self::getContainer()->get(CardReleaseTool::class);
        self::assertInstanceOf(CardReleaseTool::class, $tool);

        return $tool;
    }

    private function holds(): CardHoldRepository
    {
        $holds = self::getContainer()->get(CardHoldRepository::class);
        self::assertInstanceOf(CardHoldRepository::class, $holds);

        return $holds;
    }

    private function cardHolds(): CardHolds
    {
        $cardHolds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $cardHolds);

        return $cardHolds;
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(string $type): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE type = ? ORDER BY sequence',
            [$type],
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
